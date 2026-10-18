#!/usr/bin/perl
#------------------------------------------------------------------------------
# Check the PHP translations of the write code of the tag tables against Perl.
# Part 1 (this script): evaluate the Perl code on test inputs, write the cases.
# Part 2 (tools/check-write-code.php): evaluate the PHP closures on the same cases, compare.
#
# Usage: perl tools/check-write-code.pl [CASES_FILE] [ID...]
#        php tools/check-write-code.php [CASES_FILE]
#   CASES_FILE defaults to var/write-code-cases.jsonl; ids restrict the run to these pieces
#
# The pieces of code are found like tools/export-tables.pl does (same ids): PrintConvInv,
# ValueConvInv, RawConvInv, WriteCheck, DelCheck, WriteCondition, WriteAlso, the anonymous
# subs used as inverse conversions or write procs, and the PrintConv OTHER subs (called with
# $inv true and false). Each one is evaluated in its Writer.pl context (the "#### eval"
# comments) with $^W = 1 like the exiftool script, so implicit warnings ("isn't numeric",
# "Use of uninitialized value"...) are part of the result.
# Inputs: what reading gives for the tag on ~/exiftool/t/images (raw, ValueConv and PrintConv
# values), round trips of synthetic raw values through the tag's ValueConv and PrintConv (and
# PrintConvInv for the ValueConvInv inputs), the PrintConv lookup values, and a generic list
# (numbers, fractions, negative values, empty strings, dates with and without time zone,
# garbage...).
#------------------------------------------------------------------------------
use strict;     # (no "use warnings": the evals run with $^W = 1, like in the exiftool script)
use B;
use B::Deparse;
use Digest::SHA qw(sha1_hex);
use File::Basename qw(dirname);
use Cwd qw(abs_path);
use JSON::PP;

my $lib = "$ENV{HOME}/exiftool/lib";
my $project = abs_path(dirname(abs_path($0)) . '/..');
my $out = shift || "$project/var/write-code-cases.jsonl";
my %only = map { $_ => 1 } @ARGV;
unshift @INC, $lib;

#------------------------------------------------------------------------------
# load the modules and find the tag tables (like tools/export-tables.pl)
require Image::ExifTool;
my %skipModule = map { $_ => 1 } qw(BuildTagLookup TagInfoXML Geolocation HtmlDump Shift);
opendir my $dh, "$lib/Image/ExifTool" or die "no ExifTool library in $lib\n";
foreach my $f (sort readdir $dh) {
    next unless $f =~ /^(\w+)\.pm$/ and not $skipModule{$1};
    eval { require "Image/ExifTool/$f"; 1 } or warn "load $f: $@";
}
closedir $dh;
require 'Image/ExifTool/XMP2.pl';
require 'Image/ExifTool/XMPStruct.pl';
require 'Image/ExifTool/QuickTimeStream.pl';

no strict 'refs';
my %tables;
sub WalkPackage
{
    my $pkg = shift;
    foreach my $k (sort keys %{"${pkg}::"}) {
        if ($k =~ /::$/) {
            my $sub = substr($k, 0, -2);
            next if $sub eq 'main' or $sub =~ /^(SUPER|Lang|Charset)$/;
            WalkPackage("${pkg}::$sub");
            next;
        }
        my $glob = ${"${pkg}::"}{$k};
        next unless ref \$glob eq 'GLOB';
        my $h = *{$glob}{HASH};
        $tables{"${pkg}::$k"} = $h if $h and %$h and IsTagTable($h, "${pkg}::$k");
    }
}
sub IsTagTable
{
    my ($h, $name) = @_;
    return 0 if $name =~ /::(specialTags|tableDefaults|specialStruct)$/;
    return 1 if exists $$h{GROUPS} or exists $$h{PROCESS_PROC} or exists $$h{TABLE_NAME};
    foreach my $v (values %$h) {
        return 1 if ref $v eq 'HASH' and defined $$v{Name} and not ref $$v{Name};
        return 1 if ref $v eq 'HASH' and ref $$v{SubDirectory} eq 'HASH' and $$v{SubDirectory}{TagTable};
        return 1 if ref $v eq 'ARRAY' and @$v and ref $$v[0] eq 'HASH' and defined $$v[0]{Name};
    }
    return 0;
}
WalkPackage('Image::ExifTool');
for (my $more = 1; $more; ) {
    $more = 0;
    foreach my $t (sort keys %tables) {
        foreach my $v (values %{$tables{$t}}) {
            foreach my $i (ref $v eq 'ARRAY' ? @$v : $v) {
                next unless ref $i eq 'HASH' and ref $$i{SubDirectory} eq 'HASH';
                my $name = $$i{SubDirectory}{TagTable};
                next unless $name and not ref $name and not $tables{$name} and $name =~ /^Image::ExifTool::/;
                my $h = \%{$name};
                next unless %$h;
                $tables{$name} = $h;
                $more = 1;
            }
        }
    }
}
use strict 'refs';

#------------------------------------------------------------------------------
# find the pieces of code (same walk and ids as tools/export-tables.pl)
my $deparse = B::Deparse->new('-sC', '-q');
my %convKey = map { $_ => 1 } qw(PrintConv ValueConv RawConv PrintConvInv ValueConvInv RawConvInv);
my %exprKey = map { $_ => 1 } qw(Condition RawConv ValueConv PrintConv Hook WriteCondition WriteCheck DelCheck);
my %dropTagKey = map { $_ => 1 } qw(Notes PrintConvColumns SeparateTable Table TagID Index
    GotGroups NewTagID IsComposite Module AddedUnknown);
my %writeCtx = map { $_ => 1 } qw(PrintConvInv ValueConvInv RawConvInv WriteCheck DelCheck WriteCondition WriteAlso);
my (%piece, @pieceOrder, %onStack);

sub IsAnon { return B::svref_2object($_[0])->GV->NAME eq '__ANON__' }

# $owner: { info => tag info hash (or undef), key => key holding the code, where => text }
sub AddPiece
{
    my ($ctx, $code, $owner) = @_;
    my $src = $code;
    if (ref $code) {
        return unless IsAnon($code);
        $src = $deparse->coderef2text($code);
        $src =~ s/^\s*(use|no) (strict|warnings|feature|utf8)\b[^;]*;\n//mg;
        $src =~ s/^\s*package [\w:]+;\n//mg;
    }
    my $id = substr(sha1_hex("$ctx\0$src"), 0, 10);
    my $p = $piece{$id};
    unless ($p) {
        $p = $piece{$id} = { id => $id, ctx => $ctx, src => $src, code => $code, owners => [] };
        push @pieceOrder, $id;
    }
    push @{$$p{owners}}, $owner if @{$$p{owners}} < 40;
}

sub Conv
{
    my ($key, $c, $owner) = @_;
    return unless defined $c;
    return AddPiece($key, $c, $owner) unless ref $c;
    return AddPiece('code:conv', $c, $owner) if ref $c eq 'CODE';
    if (ref $c eq 'ARRAY') {
        foreach my $i (0 .. $#$c) {
            next if defined $$c[$i] and not ref $$c[$i] and $$c[$i] eq 'REPEAT';
            Conv($key, $$c[$i], { %$owner, index => $i });
        }
        return;
    }
    Data($c, $owner, $$owner{where});
}

sub Data
{
    my ($v, $owner, $path) = @_;
    my $r = ref $v or return;
    if ($r eq 'CODE') {
        return AddPiece($path =~ m{/OTHER$} ? 'code:OTHER' : 'code', $v, { %$owner, where => $path });
    }
    return unless $r eq 'HASH' or $r eq 'ARRAY';
    return if $onStack{$v};
    local $onStack{$v} = 1;
    if ($r eq 'ARRAY') {
        Data($_, $owner, $path) foreach @$v;
        return;
    }
    my $isStruct = $path =~ m{/Struct$};
    foreach my $k (sort keys %$v) {
        if ($isStruct and ref $$v{$k} eq 'HASH' and $k !~ /^[A-Z_]+$/) {
            TagInfo($$v{$k}, "$path/$k", { struct => 1 });
        } elsif ($k eq 'OTHER' and ref $$v{$k} eq 'CODE') {
            # (the lookup hash is passed to the OTHER sub as $conv)
            Data($$v{$k}, { %$owner, conv => $v }, "$path/$k");
        } else {
            Data($$v{$k}, $owner, "$path/$k");
        }
    }
}

# $at: { table, tagID, idx } of a table entry (to find the same TagInfo in PHP), or { struct => 1 }
sub TagInfo
{
    my ($info, $where, $at) = @_;
    return unless ref $info eq 'HASH';
    return if $onStack{$info};
    local $onStack{$info} = 1;
    my $name = defined $$info{Name} ? $$info{Name} : '';
    my $loc = "$where $name";
    foreach my $k (sort keys %$info) {
        next if $dropTagKey{$k};
        my $v = $$info{$k};
        my $owner = { info => $info, key => $k, where => $loc, at => $at };
        if ($convKey{$k}) {
            Conv($k, $v, $owner);
        } elsif ($k eq 'WriteAlso' and ref $v eq 'HASH') {
            foreach my $t (sort keys %$v) {
                ref $$v{$t} eq 'CODE' ? AddPiece('code', $$v{$t}, { %$owner, also => $t }) :
                    AddPiece('WriteAlso', $$v{$t}, { %$owner, also => $t });
            }
        } elsif ($exprKey{$k} and defined $v and not ref $v) {
            AddPiece($k, $v, $owner);
        } elsif ($k eq 'SubDirectory' and ref $v eq 'HASH') {
            foreach my $sk (sort keys %$v) {
                if (ref $$v{$sk} eq 'CODE') {
                    AddPiece('code', $$v{$sk}, { %$owner, key => $sk });
                } elsif (ref $$v{$sk}) {
                    Data($$v{$sk}, $owner, $loc);
                }
            }
        } elsif (ref $v eq 'CODE') {
            AddPiece('code', $v, $owner);
        } else {
            Data($v, $owner, "$loc/$k");
        }
    }
}

foreach my $tname (sort keys %tables) {
    my $t = $tables{$tname};
    foreach my $k (sort keys %$t) {
        my $v = $$t{$k};
        if ($Image::ExifTool::specialTags{$k}) {
            my $owner = { table => $tname, key => $k, where => "$tname $k" };
            if ($k eq 'PRINT_CONV') {
                Conv('PrintConv', $v, $owner);
            } elsif (ref $v eq 'CODE') {
                AddPiece('code', $v, $owner);
            } else {
                Data($v, $owner, "$tname $k");
            }
            next;
        }
        next if $tname eq 'Image::ExifTool::Composite';
        my $where = sprintf('%s %s', $tname, $k =~ /^\d+$/ ? sprintf('0x%.4x', $k) : $k);
        if (ref $v eq 'ARRAY') {
            TagInfo($$v[$_], $where, { table => $tname, tagID => $k, idx => $_ }) foreach 0 .. $#$v;
        } else {
            TagInfo($v, $where, { table => $tname, tagID => $k });
        }
    }
}

# the pieces to check: write code (by context or by the key holding it), and the OTHER subs
my @check;
foreach my $id (@pieceOrder) {
    my $p = $piece{$id};
    next if %only and not $only{$id};
    my $write = $writeCtx{$$p{ctx}} || $$p{ctx} eq 'code:OTHER';
    foreach my $o (@{$$p{owners}}) {
        $write = 1 if $$o{key} and ($$o{key} =~ /Inv$|^(WriteAlso|WriteProc|WRITE_PROC|WriteHook|CHECK_PROC)$/);
    }
    push @check, $p if $write;
}

# (loaded after the walk: B::Deparse output, so the ids, depends on the subs defined)
require 'Image/ExifTool/Writer.pl';

#------------------------------------------------------------------------------
# input values

# generic inputs for the $val of a conversion
my @generic = (
    '0', '1', '2', '3', '-1', '10', '100', '127', '255', '256', '1000', '65535', '4294967295', '-128',
    '0.5', '1.5', '2.8', '-2.5', '0.001', '1e3', '1/3', '3/2', '1/250', '-1/3', '+1', '+0.7', ' 5',
    '5 ', '', ' ', 'abc', '5abc', 'inf', 'undef', 'n/a', 'none', 'None', 'Auto', 'Off', 'On', 'Bulb',
    'Unknown (5)', 'Unknown (0x1f)', '0x1f', '0x8000', 'ff', 'A1B2', '1 2', '1 2 3', '1 2 3 4',
    '1 2 3 4 5 6 7 8 9', '1.2.3.4', '1.23', '01.00', '10.2a', 'Rev 1.00', 'Rev01.00', 'Hi 0.3', '1,5',
    '2024:01:02 03:04:05', '2024:01:02 03:04:05+02:00', '2024:01:02 03:04:05-05:30',
    '2024:01:02 03:04:05Z', '2024:01:02 03:04:05.123+01:00', '1999:12:31 23:59:59 DST',
    '2024:01:02', '2024-01-02T03:04:05', '12:34:56', '12:34:56.78', '23:59:60+14:00', '0000:00:00 00:00:00',
    '48.8566', '-33.8688', q{48 deg 51' 24.00" N}, q{2 deg 21' 3.20" W}, '48.8566 N', '48.8566, 2.3522',
    '48.8566 2.3522', '-33.86 151.2', '12 mm', '12.5mm', '3.5 m', '+0.7 EV', '50%', '5 C', '1.2 V',
    '3200 K', '100 kPa', '2x', '24-70 mm', 'f/2.8', '1.5 s', '30 fps', "line1\nline2", "a\0b",
    "caf\xe9", "\xc3\xa9t\xc3\xa9", 'UTF8', 'Tagged: 1, ColorClass: 2, Rating: 3, FrameNum: 4',
    'Entity=abcd Index=5', 'Entity=abcd Criteria=efgh', 'abcdef0123456789abcdef0123456789',
    'II', 'MM', 'II*', 'Lat=48.85 Lon=2.35 Alt=35 Body=earth Notes=hi Role=real', 'shooting',
    '1999:12:31 23:59', 'Unknown (1.2.3)', '0 0', '640x480', '640 480', '1.2.3', 'N', 'S', 'E', 'W',
    '1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17', 'Mbps', '12.3 Mbps', '1:02:03', '1:02:03.5',
    '-1:00:00:00', 'F', 'AF-S DX 18-55mm f/3.5-5.6G VR', 'MF D G E', 'geotag', 'Paris, France',
);

my $jpeg = do { local $/; open my $jfh, '<', "$ENV{HOME}/exiftool/t/images/Writer.jpg" or die; binmode $jfh; <$jfh> };

# values that reading gives for each tag info (by address) on the test images
my %fileVal;    # address => { Raw => { val => 1 }, ValueConv => ..., PrintConv => ... }
my %fileBin;    # name => [ binary values ] (for the WriteCheck of whole data blocks)
{
    my $n = 0;
    foreach my $file (sort glob("$ENV{HOME}/exiftool/t/images/*")) {
        my $et = Image::ExifTool->new;
        $et->Options(Duplicates => 1, Unknown => 1, Binary => 1);
        $et->ImageInfo($file, qw(* EXIF XMP ICC_Profile IPTC CanonVRD CanonDR4 PreviewImage));
        foreach my $key (keys %{$$et{VALUE}}) {
            my $info = $$et{TAG_INFO}{$key} or next;
            my $name = $$info{Name};
            foreach my $type (qw(Raw ValueConv PrintConv)) {
                my $v = eval { $et->GetValue($key, $type) };
                foreach my $x (ref $v eq 'ARRAY' ? @$v : $v) {
                    next unless defined $x;
                    if (ref $x eq 'SCALAR') {
                        push @{$fileBin{$name}}, $$x if $type eq 'Raw' and length($$x) < 200000 and
                            @{$fileBin{$name} || []} < 3;
                        next;
                    }
                    next if ref $x or length($x) > 300;
                    my $h = $fileVal{$info}{$type} ||= { };
                    $$h{$x} = ++$n if keys(%$h) < 25 and not $$h{$x};
                }
            }
        }
    }
}

# convert a raw value with the forward conversions of a tag (undef on warning or error)
sub Forward
{
    my ($info, $type, $raw) = @_;
    my $et = Image::ExifTool->new;
    local $SIG{__WARN__} = sub { die "warn\n" };
    my $v = eval { $et->GetValue($info, $type, $raw) };
    return undef if $@ or ref $v or $$et{VALUE}{Warning};
    return $v;
}

# PrintConvInv of a value (what ConvInv passes to ValueConvInv), undef on warning or error
sub PrintConvInv
{
    my ($info, $prt) = @_;
    my $pinv = $$info{PrintConvInv};
    return undef unless defined $prt and defined $pinv and (not ref $pinv or ref $pinv eq 'CODE');
    my $r = EvalCode(ref $pinv ? 'code:conv' : 'PrintConvInv', $pinv, $prt);
    return undef if defined $$r{warn} or defined $$r{die} or ref $$r{ret};
    return $$r{ret};
}

my @rawNums = (0, 1, 2, 3, 4, 5, 7, 8, 10, 12, 16, 20, 24, 32, 50, 64, 96, 100, 127, 128, 200, 255,
    256, 300, 500, 1000, 1024, 4096, 32767, 32768, 65535, 65536, -1, -2, -8, -100, 0.5, 1.5, 2.25);
my @rawStr = ('0100', '0220', '1 2 3 4', '2024:01:02 03:04:05', 'abc', '');

# inputs of a piece (list of [ $val ] or [ $val, ...extra ] cases)
sub Inputs
{
    my $p = shift;
    my (%seen, @in);
    # (inputs are strings: a number computed by a forward conversion is stringified like the
    # value the PHP side gets, and keeps no cached numeric value)
    my $add = sub { foreach (@_) { next if not defined $_ or ref $_; push @in, "$_" unless $seen{$_}++ } };
    my $ctx = $$p{ctx};
    foreach my $o (@{$$p{owners}}) {
        my $info = $$o{info} or next;
        my $key = $$o{key} || '';
        my $fv = $fileVal{$info} || { };
        my $type = $key =~ /^(PrintConv|ValueConv|RawConv)/ ? $1 : '';
        $type = 'PrintConv' if $ctx eq 'code:OTHER' or $key =~ /^(WriteAlso|WriteCheck)$/;
        if ($type eq 'PrintConv') {
            $add->(sort { $$fv{PrintConv}{$a} <=> $$fv{PrintConv}{$b} } keys %{$$fv{PrintConv}});
            # values of the PrintConv lookup
            my $pc = $$info{PrintConv};
            if (ref $pc eq 'HASH') {
                my @v = grep { not ref } map { $$pc{$_} } sort grep !/^[A-Z]+$/, keys %$pc;
                $add->(@v[0 .. ($#v < 30 ? $#v : 30)]);
                $add->(map { "$_" } (sort keys %{$$pc{BITMASK}})[0..3]) if ref $$pc{BITMASK} eq 'HASH';
            }
        }
        if ($type eq 'ValueConv' or $ctx eq 'WriteAlso') {
            $add->(sort { $$fv{ValueConv}{$a} <=> $$fv{ValueConv}{$b} } keys %{$$fv{ValueConv}});
            $add->(map { PrintConvInv($info, $_) } sort { $$fv{PrintConv}{$a} <=> $$fv{PrintConv}{$b} } keys %{$$fv{PrintConv}});
        }
        if ($type eq 'RawConv' or $ctx eq 'WriteCheck') {
            $add->(sort { $$fv{Raw}{$a} <=> $$fv{Raw}{$b} } keys %{$$fv{Raw}});
            $add->(sort { $$fv{ValueConv}{$a} <=> $$fv{ValueConv}{$b} } keys %{$$fv{ValueConv}});
        }
        if ($ctx eq 'WriteCheck' and defined $$info{Name} and $fileBin{$$info{Name}}) {
            push @in, @{$fileBin{$$info{Name}}};
        }
        # round trips of synthetic raw values
        if ($type) {
            foreach my $raw (@rawNums, @rawStr) {
                my $v = Forward($info, 'ValueConv', $raw);
                if ($type eq 'PrintConv') {
                    $add->(Forward($info, 'PrintConv', $raw));
                } elsif ($type eq 'ValueConv') {
                    $add->($v);
                    # ...and what PrintConvInv gives for the PrintConv value
                    $add->(PrintConvInv($info, Forward($info, 'PrintConv', $raw)));
                } else {
                    $add->($raw);
                }
            }
        }
    }
    $add->(@generic);
    # a small JPEG image for the code that looks at image data
    push @in, $jpeg if $$p{src} =~ /ImageInfo|CheckImage|\\xff\\xd8/;
    return @in;
}

#------------------------------------------------------------------------------
# evaluate a piece in its context; returns { ret, warn, die, val, opts, selfWarn }
sub Enc
{
    my $v = shift;
    return undef unless defined $v;
    my $r = ref $v;
    return { a => [ map { Enc($_) } @$v ] } if $r eq 'ARRAY';
    return { h => { map { $_ => Enc($$v{$_}) } keys %$v } } if $r eq 'HASH';
    return { s => Enc($$v) } if $r eq 'SCALAR';
    return { r => $r } if $r;
    my $s = "$v";
    return $s if $s =~ /^[\x20-\x7e]*\z/;
    if (utf8::is_utf8($s)) {
        utf8::encode($s);
        return { u => unpack('H*', $s) };
    }
    return { x => unpack('H*', $s) };
}

my %selfKeys = map { $_ => 1 } qw(FILE_TYPE TIFF_TYPE FileType FocalUnits Model TextEncoding PrimaryItem);

package Image::ExifTool;
our $evalWarning;

# (the evals run here: package Image::ExifTool, "use strict", no lexical warnings like Writer.pl)
sub WCC_Eval
{
    my ($wccCtx, $wccCode, $wccArgs, $wccState) = @_;
    my $self = Image::ExifTool->new;
    my $et = $self;
    my %wccRes;
    $$self{TIFF_TYPE} = '';     # (set by Init() when reading or writing, a property default in PHP)
    foreach (sort keys %{$$wccState{opts} || {}}) {
        $self->Options($_ => $$wccState{opts}{$_});
    }
    $$self{$_} = $$wccState{self}{$_} foreach keys %{$$wccState{self} || {}};
    SetByteOrder($$wccState{order} || 'MM');
    my @wccWarn;
    my ($val, $wantGroup, $tagInfo, %opts, $inv, $conv);
    $val = $$wccArgs{val};
    $val = "$val" if defined $val and not ref $val;    # (a fresh string, no cached number)
    $wantGroup = $$wccArgs{wantGroup};
    $tagInfo = $$wccArgs{info} || { Name => 'Test' };
    $inv = $$wccArgs{inv};
    $conv = $$wccArgs{conv};
    %opts = (Type => 'ValueConv', Protected => 2, AddValue => undef, DelValue => undef,
             Shift => undef, Replace => undef, CreateGroups => undef, SetTags => undef);
    my ($tag, $wgrp1, $convType, $type, $err) = ('Test', 'Test', 'PrintConv', $wccCtx, undef);
    {
        local $SIG{'__WARN__'} = sub { push @wccWarn, $_[0]; SetWarning($_[0]) };
        undef $evalWarning;
        # (no stale $1 for the code: Perl gives the capture of the caller's last successful
        # match after a failed one, a value the PHP port can't know, so it is undef here)
        'x' =~ /x/;
        my $wccRet;
        if (ref $wccCode eq 'CODE') {
            if ($wccCtx eq 'code:OTHER') {
                $wccRet = eval { &$wccCode($val, $inv, $conv) };
            } elsif ($wccCtx eq 'code' and $$wccArgs{proc}) {
                $wccRet = eval { &$wccCode($self, { }, { }) };
            } elsif ($wccCtx eq 'code' and $$wccArgs{hook}) {
                $wccRet = eval { &$wccCode($val, $self) };
            } else {
                $wccRet = eval { &$wccCode($val, $self) };
            }
        } elsif ($wccCtx eq 'WriteCondition') {
            #### eval WriteCondition ($self)
            $wccRet = eval $wccCode;
        } elsif ($wccCtx eq 'WriteAlso') {
            #### eval WriteAlso ($val,%opts)
            $wccRet = eval $wccCode;
        } else {
            # PrintConvInv/ValueConvInv ($val, $self, $wantGroup), RawConvInv ($self, $val, $tagInfo),
            # WriteCheck ($self, $tagInfo, $val), DelCheck ($self, $tagInfo, $wantGroup)
            $wccRet = eval $wccCode;
        }
        $wccRes{die} = $@ if $@;
        $wccRes{warn} = $evalWarning if defined $evalWarning;
        $wccRes{ret} = $wccRet;
    }
    $wccRes{val} = $val;
    $wccRes{opts} = { %opts };
    $wccRes{selfWarn} = [ map { $$self{VALUE}{$_} } sort grep /^Warning\b/, keys %{$$self{VALUE}} ];
    $wccRes{selfKeys} = { map { $_ => $$self{$_} } grep { $selfKeys{$_} } keys %$self };
    return \%wccRes;
}

package main;

sub EvalCode
{
    my ($ctx, $code, $val) = @_;
    return Image::ExifTool::WCC_Eval($ctx, $code, { val => $val }, { });
}

# CleanWarning of a message (like the caller does) plus a flag for the empty "\n" warning
sub Clean
{
    my $w = shift;
    return undef unless defined $w;
    return "\n" if $w eq "\n";
    return Image::ExifTool::CleanWarning($w);
}

#------------------------------------------------------------------------------
# run the cases
my $json = JSON::PP->new->canonical;
open my $fh, '>', $out or die "$out: $!\n";
my $nCases = 0;
$^W = 1;
foreach my $p (@check) {
    my $ctx = $$p{ctx};
    my $src = $$p{src};
    my @states = ({ });
    # variations of the ExifTool object for code that looks at it
    my %var = (
        FILE_TYPE => [ 'JPEG', 'TIFF' ], TIFF_TYPE => [ 'CR2', 'TIFF' ], FileType => [ 'CR3', 'JPEG' ],
        FocalUnits => [ 32 ], Model => [ 'DiMAGE A2', 'DiMAGE 7Hi' ], TextEncoding => [ 'UTF8', 'UCS2' ],
    );
    foreach my $k (sort keys %var) {
        next unless $src =~ /\{\s*'?$k'?\s*\}/;
        push @states, { self => { $k => $_ } } foreach @{$var{$k}};
    }
    push @states, { opts => { QuickTimeUTC => 1 } } if $src =~ /QuickTimeUTC/;
    push @states, { opts => { CharsetEXIF => 'Latin' } } if $src =~ /CharsetEXIF/;
    push @states, { order => 'II' } if $src =~ /ByteOrder|Set(8|16|32|64|Fixed|Rational)/;
    my @cases;
    if ($ctx eq 'WriteCondition' or $ctx eq 'DelCheck') {
        @cases = map { { wantGroup => $_ } } (undef, 'XMP', 'EXIF');
    } elsif ($ctx eq 'code' and grep { ($$_{key} || '') =~ /PROC$|Proc$/ } @{$$p{owners}}) {
        @cases = ({ proc => 1 });
    } else {
        my @in = Inputs($p);
        push @in, undef if $ctx eq 'WriteAlso';   # (deleting the tag)
        @cases = map { { val => $_ } } @in;
        if ($ctx eq 'code' and grep { ($$_{key} || '') eq 'WriteHook' } @{$$p{owners}}) {
            @cases = map { { val => $_, hook => 1 } } "\0\0\0\0\0\x01\0\x02", "\0\0\0\0\x12\x34\x56\x78", @in;
        }
    }
    my $owner = $$p{owners}[0];
    my $info = $$owner{info};
    foreach my $state (@states) {
        foreach my $case (@cases) {
            my @runs = ($case);
            if ($ctx eq 'code:OTHER') {
                # (the inverse conversion: forward ones were checked with the reading values)
                @runs = ({ %$case, inv => 1, conv => $$owner{conv} });
            }
            foreach my $run (@runs) {
                my $res = Image::ExifTool::WCC_Eval($ctx, $$p{code}, { %$run, info => $info }, $state);
                my %rec = (
                    id => $$p{id}, ctx => $ctx,
                    in => { val => Enc($$run{val}), wantGroup => $$run{wantGroup}, inv => $$run{inv},
                            hook => $$run{hook}, proc => $$run{proc}, state => $state,
                            at => ($$owner{at} ? { %{$$owner{at}}, tagID => Enc($$owner{at}{tagID}) } : undef),
                            key => $$owner{key}, where => Enc($$owner{where}) },
                    ret => Enc($$res{ret}), warn => Enc(Clean($$res{warn})), die => Enc(Clean($$res{die})),
                    val => Enc($$res{val}), opts => Enc($$res{opts}), selfWarn => Enc($$res{selfWarn}),
                    selfKeys => Enc($$res{selfKeys}),
                );
                print $fh $json->encode(\%rec), "\n";
                ++$nCases;
            }
        }
    }
}
close $fh;
printf STDERR "%d pieces, %d cases written to %s\n", scalar(@check), $nCases, $out;
# the Perl source of the pieces, for reference
(my $srcFile = $out) =~ s/(\.jsonl)?$/-src.txt/;
open $fh, '>', $srcFile or die "$srcFile: $!\n";
print $fh "==== $$_{id} $$_{ctx} $$_{owners}[0]{where}\n$$_{src}\n\n" foreach @check;
close $fh;
