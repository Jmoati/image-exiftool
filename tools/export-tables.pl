#!/usr/bin/perl
#------------------------------------------------------------------------------
# Generate the PHP tag tables of the port from the Perl ExifTool library.
#
# Usage: perl tools/export-tables.pl [EXIFTOOL_LIB] [PROJECT_DIR]
#   EXIFTOOL_LIB defaults to ~/exiftool/lib, PROJECT_DIR to the parent of tools/
#
# Writes:
#   tables/<Module>.php        tag tables of each Perl module (+ load-time actions)
#   tables/vars/<Module>.php   class Image\ExifTool\Vars\<Module>: package variables and
#                              file lexicals of the module (lens lists, file types...)
#   tables/_index.php          table name => module
#   tables/_todo/<Module>.txt  Perl code found in tables with no translation yet
# Reads translations/*.txt (see PORTING.md).
#------------------------------------------------------------------------------
use strict;
use warnings;
use B;
use B::Deparse;
use PadWalker ();
use Digest::SHA qw(sha1_hex);
use File::Basename qw(dirname);
use File::Path qw(make_path remove_tree);
use Cwd qw(abs_path);

my $lib = shift || "$ENV{HOME}/exiftool/lib";
my $project = shift || abs_path(dirname(abs_path($0)) . '/..');
unshift @INC, $lib;

# modules we don't port (writing, documentation, translations, geolocation database)
my %skipModule = map { $_ => 1 } qw(BuildTagLookup TagLookup TagInfoXML Geolocation HtmlDump Shift);

# load-time actions recorded per module (AddCompositeTags calls)
my %loadActions;
require Image::ExifTool;
{
    no warnings 'redefine';
    my $orig = \&Image::ExifTool::AddCompositeTags;
    *Image::ExifTool::AddCompositeTags = sub ($;$) {
        my ($add, $override) = @_;
        unless (ref $add) {
            my $mod = ModuleKey($add . '::Composite');
            push @{$loadActions{$mod}}, [ 'AddCompositeTags', $add, $override ? 1 : 0 ];
        }
        goto &$orig;
    };
}
opendir my $dh, "$lib/Image/ExifTool" or die "no ExifTool library in $lib\n";
my @files = sort readdir $dh;
closedir $dh;
foreach my $f (@files) {
    next unless $f =~ /^(\w+)\.pm$/ and not $skipModule{$1};
    eval { require "Image/ExifTool/$f"; 1 } or warn "load $f: $@";
}
# character set maps (Image::ExifTool::Charset::Latin...)
my %charsets;
foreach my $f (glob("$lib/Image/ExifTool/Charset/*.pm")) {
    my ($cs) = $f =~ m{/(\w+)\.pm$};
    require "Image/ExifTool/Charset/$cs.pm";
    no strict 'refs';
    $charsets{$cs} = \%{"Image::ExifTool::Charset::$cs"};
}
require 'Image/ExifTool/XMP2.pl';
require 'Image/ExifTool/XMPStruct.pl';
require 'Image/ExifTool/QuickTimeStream.pl';

my $version = $Image::ExifTool::VERSION;

#------------------------------------------------------------------------------
# find tag tables and module variables
no strict 'refs';
my (%tables, %varsOf, %pkgSubs);
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
        my $name = "${pkg}::$k";
        if (my $code = *{$glob}{CODE}) {
            my $gv = B::svref_2object($code)->GV;
            # only subs defined in this package (not imported ones)
            $pkgSubs{$pkg}{$k} = $code if $gv->STASH->NAME eq $pkg and $gv->NAME eq $k;
        }
        my $h = *{$glob}{HASH};
        if ($h and %$h and IsTagTable($h, $name)) {
            $tables{$name} = $h;
            next;
        }
        next if $k =~ /^(ISA|EXPORT|EXPORT_OK|EXPORT_TAGS|VERSION|AUTOLOAD|BEGIN|END|INC|ENV|ARGV|_<.*|[^\w].*|\d+)$/;
        # (empty ones too: %Image::ExifTool::userLens is filled at run time)
        $varsOf{$pkg}{"%$k"} = $h if $h;
        my $a = *{$glob}{ARRAY};
        $varsOf{$pkg}{"\@$k"} = $a if $a and (@$a or $k !~ /^(ARGV|INC|_)$/ and defined *{$glob}{ARRAY});
        my $s = *{$glob}{SCALAR};
        $varsOf{$pkg}{"\$$k"} = $s if $s and defined $$s and not ref $$s and $k ne 'a' and $k ne 'b';
    }
}
sub IsTagTable
{
    my ($h, $name) = @_;
    return 0 if $name =~ /::(specialTags|tableDefaults|specialStruct)$/;
    return 1 if exists $$h{GROUPS} or exists $$h{PROCESS_PROC} or exists $$h{TABLE_NAME};
    foreach my $v (values %$h) {
        return 1 if ref $v eq 'HASH' and defined $$v{Name} and not ref $$v{Name};
        # (entries may have only a SubDirectory, like %Image::ExifTool::Ogg::Main)
        return 1 if ref $v eq 'HASH' and ref $$v{SubDirectory} eq 'HASH' and $$v{SubDirectory}{TagTable};
        return 1 if ref $v eq 'ARRAY' and @$v and ref $$v[0] eq 'HASH' and defined $$v[0]{Name};
    }
    return 0;
}
WalkPackage('Image::ExifTool');
# hashes used as a SubDirectory TagTable are tag tables too, even when no entry has a Name
# (the PDF dictionaries: Kids, Resources, XObject...)
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
                my ($pkg, $var) = $name =~ /^(.*)::(\w+)$/;
                delete $varsOf{$pkg}{"%$var"} if $varsOf{$pkg};
                $more = 1;
            }
        }
    }
}
use strict 'refs';

# module key of a package or table name: Image::ExifTool::Canon::Main => Canon
sub ModuleKey
{
    my $name = shift;
    return $1 if $name =~ /^Image::ExifTool::(\w+)::/;
    return 'ExifTool';
}
sub ClassName
{
    my $mod = shift;
    return $mod =~ /^\d/ ? "_$mod" : $mod;
}

#------------------------------------------------------------------------------
# translations
my %translation;
foreach my $file (glob("$project/translations/*.txt")) {
    open my $fh, '<', $file or die;
    my ($id, $body);
    while (my $line = <$fh>) {
        if ($line =~ /^==== (\w+) (\S+)/) {
            $translation{$id} = $body if defined $id;
            ($id, $body) = ($1, '');
            next;
        }
        next unless defined $id;
        next if $body eq '' and $line =~ /^#/;
        $body .= $line;
    }
    $translation{$id} = $body if defined $id;
    close $fh;
}
foreach (values %translation) { s/\s+$//; }
# skip translations that don't parse (a translator may be editing its file right now)
if (%translation) {
    require JSON::PP;
    my $tmp = "/tmp/export-tables-$$.json";
    open my $jfh, '>', $tmp or die;
    print $jfh JSON::PP->new->utf8(0)->encode(\%translation);
    close $jfh;
    my $bad = `php "$project/tools/parse-check.php" < "$tmp"`;
    unlink $tmp;
    foreach my $line (split /\n/, $bad) {
        my ($id, $msg) = split /\t/, $line, 2;
        warn "translation $id ignored: $msg\n";
        delete $translation{$id};
    }
}

#------------------------------------------------------------------------------
# PHP emitters

my %TagTableSpecial = %Image::ExifTool::specialTags;
my %dropTagKey = map { $_ => 1 } qw(Notes PrintConvInv ValueConvInv RawConvInv WriteCondition
    WriteCheck DelCheck WriteAlso WriteHook PrintConvColumns SeparateTable Table TagID Index
    GotGroups NewTagID IsComposite Module AddedUnknown Shift);
my %dropTableKey = map { $_ => 1 } qw(WRITE_PROC CHECK_PROC NOTES TABLE_NAME SHORT_NAME);
my %dropSubdirKey = map { $_ => 1 } qw(WriteProc CheckProc);
# keys whose string values are Perl expressions
my %exprKey = map { $_ => 1 } qw(Condition RawConv ValueConv PrintConv Hook);
my %subdirExprKey = map { $_ => 1 } qw(Start Base OffsetPt Validate ChangeBase WrongBase FixOffsets);

my $deparse = B::Deparse->new('-sC', '-q');
my $sigs = {
    Condition  => 'static function (ExifTool $self, $valPt, $format, $count)',
    RawConv    => 'static function ($val, ExifTool $self, $tag, ?TagInfo $tagInfo, &$priority, array &$grps, array $vals)',
    ValueConv  => 'static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag)',
    PrintConv  => 'static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag)',
    Hook       => 'static function (ExifTool $self, &$format, &$varSize, $size, $dataPt, $pos): void',
    FormatCount=> 'static function (array $val, $size, $varSize, ExifTool $self)',
    Start      => 'static function ($valuePtr = null, $val = null, $dirStart = null, $dataPt = null)',
    Base       => 'static function ($start, $base = 0)',
    OffsetPt   => 'static function ($valuePtr)',
    Validate   => 'static function ($val = null, $dirData = null, $subdirStart = null, $size = null, $type = null)',
    IsOffset   => 'static function ($val, ExifTool $et)',
    ChangeBase => 'static function ($dirStart, $dataPos)',
    WrongBase  => 'static function (ExifTool $self)',
    FixOffsets => 'static function (&$valuePtr, &$valEnd, $size, $tagID, $wFlag)',
    'code:conv'  => 'static function ($val, $et)  // called as $conv($val, $self); use the parameter names of the Perl sub',
    'code:OTHER' => 'static function ($val, $inv, $conv)  // called as $other($val, $inv, $conv)',
    'code'       => 'static function (...)  // same arguments as the Perl sub (see its @_ use)',
};

my %tableByAddr;
$tableByAddr{$tables{$_}} = $_ foreach keys %tables;
our %onStack;

# per-module emission state
my ($mod, %closures, @closureOrder, %todo, %shared, @sharedOrder, %sharedCount, $nShared);

sub PhpString
{
    my $s = shift;
    if (utf8::is_utf8($s)) {
        $s = "$s";
        utf8::encode($s);
    }
    if ($s !~ /[^\x20-\x7e]/) {
        $s =~ s/([\\'])/\\$1/g;
        return "'$s'";
    }
    $s =~ s/([\\"\$])/\\$1/g;
    $s =~ s/([^\x20-\x7e])/sprintf('\\x%.2x', ord($1))/ge;
    return "\"$s\"";
}

sub PhpKey
{
    my $k = shift;
    # a hash keyed by tag table references (%otherTable in ID3.pm): use the table name
    $k = $tableByAddr{$k} if $k =~ /^HASH\(0x[0-9a-f]+\)$/ and $tableByAddr{$k};
    return $k if $k =~ /^(0|-?[1-9]\d{0,17})$/;
    return PhpString($k);
}

sub PhpScalar
{
    my $v = shift;
    return 'null' unless defined $v;
    my $f = B::svref_2object(\$v)->FLAGS;
    my ($isInt, $isNum, $isStr) = ($f & B::SVf_IOK, $f & B::SVf_NOK, $f & B::SVf_POK);
    if ($isInt and not $isNum and (not $isStr or "$v" =~ /^(-?[1-9]\d*|0)$/)) {
        my $s = "$v";
        if ($s =~ /^(-?)(\d+)$/) {
            my ($neg, $abs) = ($1, $2);
            # 64-bit values stay exact: an int when PHP can hold it, else the digits as a string
            return $s if length($abs) < 19 or (length($abs) == 19 and $abs le '9223372036854775807');
            return 'PHP_INT_MIN' if $neg and $abs eq '9223372036854775808';
            return PhpString($s);
        }
    }
    if (($isNum or $isInt) and (not $isStr or "$v" eq (0 + $v) . '')) {
        return 'INF' if $v == 9**9**9;
        return '-INF' if $v == -9**9**9;
        return 'NAN' if $v != $v;
        my $s = sprintf('%.15g', $v);
        $s = sprintf('%.17g', $v) unless $s == $v;
        $s .= '.0' unless $s =~ /[.eE]/ or ($isInt and not $isNum and $s =~ /^-?\d+$/ and length $s < 19);
        return $s;
    }
    return PhpString($v);
}

# is this string a plain number (no expression)?
sub IsNumber { my $v = shift; return (defined $v and not ref $v and $v =~ /^\s*[-+]?(0x[0-9a-f]+|\d+(\.\d*)?([eE][-+]?\d+)?)\s*$/i) }

sub NumOf { my $v = shift; $v =~ s/^\s+|\s+$//g; return $v =~ /^[-+]?0x/i ? hex($v) : $v + 0 }

# a Perl sub given by name in a string ('Image::ExifTool::PLIST::ProcessBinaryPLIST') => PHP callable
sub SubName
{
    my $name = shift;
    return undef unless defined $name and not ref $name and $name =~ /^Image::ExifTool::(?:(\w+)::)?(\w+)$/;
    my $class = defined $1 ? ClassName($1) : 'ExifTool';
    return PhpString("Image\\ExifTool\\${class}::$2");
}

# register a piece of Perl code; returns the PHP variable holding its closure
sub Code
{
    my ($context, $src, $where, $extra) = @_;
    my $id = substr(sha1_hex("$context\0$src"), 0, 10);
    my $var = "\$f_$id";
    unless ($closures{$id}) {
        my $php = $translation{$id};
        my $scalars = $$extra{scalars} || {};
        if (defined $php) {
            if (%$scalars) {
                # closed-over scalars are defined in an enclosing scope for "use (...)"
                my $init = join ' ', map { "$_ = " . PhpScalar(${$$scalars{$_}}) . ';' } sort keys %$scalars;
                $php = "(static function () { $init return $php; })()";
            }
        } else {
            $php = "static function (mixed ...\$args) { throw new NotPorted('$context $id'); }";
            $todo{$id} ||= [ $context, $src, $where, $extra ];
        }
        $closures{$id} = $php;
        push @closureOrder, $id;
    }
    return $var;
}

# code reference: named sub => string callable, anonymous sub => translated closure
sub CodeRef
{
    my ($code, $context, $where) = @_;
    my $cv = B::svref_2object($code);
    my $gv = $cv->GV;
    my $name = $gv->NAME;
    my $pkg = $gv->STASH->NAME;
    if ($name ne '__ANON__') {
        my $class = $pkg eq 'Image::ExifTool' ? 'ExifTool' : ClassName(ModuleKey("${pkg}::x"));
        if ($pkg =~ /^Image::ExifTool::(\w+)::(\w+)$/) {
            # sub-package: keep its name in the method (Image::ExifTool::Nikon::Foo::Bar => Nikon::Foo_Bar)
            return PhpString("Image\\ExifTool\\" . ClassName($1) . "::${2}_$name");
        }
        $class = 'File\\RandomAccess' if $pkg eq 'File::RandomAccess';
        return PhpString("Image\\ExifTool\\${class}::$name");
    }
    my $src = $deparse->coderef2text($code);
    $src =~ s/^\s*(use|no) (strict|warnings|feature|utf8)\b[^;]*;\n//mg;
    $src =~ s/^\s*package [\w:]+;\n//mg;
    # closed-over lexicals: hashes and arrays live in the Vars class, scalars are passed in
    my $closed = PadWalker::closed_over($code);
    my (%scalars, @vars);
    foreach my $v (sort keys %$closed) {
        if ($v =~ /^\$(\w+)/) {
            $scalars{"\$$1"} = $$closed{$v};
        } else {
            my $phpName = LexicalName($pkg, $v, $$closed{$v});
            push @vars, "$v => Vars\\" . ClassName(ModuleKey("${pkg}::x")) . "::\$$phpName";
        }
    }
    return Code($context, $src, $where, { scalars => \%scalars, vars => \@vars, package => $pkg });
}

# lexical variables dumped into the Vars class: name => ref
my %lexicals;   # module => { phpName => [ perlName, ref ] }
sub LexicalName
{
    my ($pkg, $perlName, $ref) = @_;
    my $m = ModuleKey("${pkg}::x");
    (my $base = $perlName) =~ s/^[\$\@%]//;
    my $name = $base;
    my $n = 1;
    while (my $e = $lexicals{$m}{$name}) {
        last if $$e[1] == $ref;
        $name = $base . '_' . ++$n;
    }
    $lexicals{$m}{$name} = [ $perlName, $ref ];
    return $name;
}

# emit a data structure (no code allowed when $static: Vars class initializers)
sub Data
{
    my ($v, $ind, $static, $path) = @_;
    return PhpScalar($v) unless ref $v;
    my $r = ref $v;
    if ($r eq 'CODE') {
        if ($static) {
            my $cv = B::svref_2object($v);
            return CodeRef($v, 'code', $path) if $cv->GV->NAME ne '__ANON__';
            return "null /* anonymous sub: port by hand */";
        }
        return CodeRef($v, $path =~ m{/OTHER$} ? 'code:OTHER' : 'code', $path);
    }
    if ($r eq 'SCALAR' or $r eq 'REF') {
        return $static ? PhpScalar($$v) : 'new ScalarRef(' . PhpScalar($$v) . ')';
    }
    if ($r eq 'Regexp') {
        return PhpString("$v");
    }
    if ($r ne 'HASH' and $r ne 'ARRAY') {
        return 'null /* ' . $r . ' */';
    }
    # a reference to a tag table is emitted as its name (use ExifTool::GetTagTable($name))
    if ($r eq 'HASH' and $tableByAddr{$v} and not $$ind{isTableBody}) {
        return PhpString($tableByAddr{$v});
    }
    # shared structure (used more than once in this module): emit once
    if (not $static and $sharedCount{$v} and $sharedCount{$v} > 1 and not $$ind{inShared}) {
        unless ($shared{$v}) {
            return 'null /* circular reference */' if $onStack{$v};
            my $name = '$d_' . ++$nShared;
            $shared{$v} = [ $name, undef ];
            $shared{$v}[1] = Data($v, { %$ind, depth => 0, inShared => 1 }, 0, $path);
            push @sharedOrder, $v;   # after its body: inner shared structures come first
        }
        # a structure containing itself (recursive XMP structures): PHP arrays can't
        return 'null /* circular reference */' unless defined $shared{$v}[1];
        return $shared{$v}[0];
    }
    if ($onStack{$v}) {
        return 'null /* circular reference */';
    }
    local $onStack{$v} = 1;
    my $depth = $$ind{depth} || 0;
    my $pad = '    ' x ($depth + 1);
    my $end = '    ' x $depth;
    my %sub = (%$ind, depth => $depth + 1, inShared => 0);
    my @items;
    if ($r eq 'ARRAY') {
        push @items, Data($_, \%sub, $static, $path) foreach @$v;
        return '[]' unless @items;
        my $one = '[' . join(', ', @items) . ']';
        return $one if length($one) < 100 and $one !~ /\n/;
        return "[\n" . join('', map { "$pad$_,\n" } @items) . "$end]";
    }
    my $isStruct = $path =~ m{/Struct$};
    foreach my $k (SortKeys($v)) {
        my $item = $$v{$k};
        if ($isStruct and not $static and ref $item eq 'HASH' and $k !~ /^[A-Z_]+$/) {
            # XMP structure field: a tag information hash
            push @items, PhpKey($k) . ' => ' . TagInfo($item, \%sub, "$path/$k");
            next;
        }
        push @items, PhpKey($k) . ' => ' . Data($item, \%sub, $static, "$path/$k");
    }
    return '[]' unless @items;
    my $one = '[' . join(', ', @items) . ']';
    return $one if length($one) < 100 and $one !~ /\n/;
    return "[\n" . join('', map { "$pad$_,\n" } @items) . "$end]";
}

sub SortKeys
{
    my $h = shift;
    return sort {
        my $na = $a =~ /^-?\d+(?:\.\d+)?$/ ? 1 : 0;
        my $nb = $b =~ /^-?\d+(?:\.\d+)?$/ ? 1 : 0;
        $na && $nb ? ($a <=> $b || $a cmp $b) : $na ? -1 : $nb ? 1 : $a cmp $b
    } keys %$h;
}

# conversion value (PrintConv/ValueConv/RawConv): string expr, code, hash lookup or array of them
sub Conv
{
    my ($key, $c, $ind, $where) = @_;
    return 'null' unless defined $c;
    return Code($key, $c, $where) unless ref $c;
    return CodeRef($c, 'code:conv', $where) if ref $c eq 'CODE';
    if (ref $c eq 'ARRAY') {
        my @items = map { defined $_ && $_ eq 'REPEAT' ? "'REPEAT'" : Conv($key, $_, { %$ind, depth => ($$ind{depth} || 0) + 1 }, $where) } @$c;
        return 'new ConvList([' . join(', ', @items) . '])';
    }
    return Data($c, $ind, 0, $where);
}

sub TagInfo
{
    my ($info, $ind, $where) = @_;
    return PhpScalar($info) unless ref $info;
    return Data($info, $ind, 0, $where) unless ref $info eq 'HASH';
    my $depth = $$ind{depth} || 0;
    my $pad = '    ' x ($depth + 1);
    my $end = '    ' x $depth;
    my %sub = (%$ind, depth => $depth + 1);
    my $name = defined $$info{Name} ? $$info{Name} : '';
    my $loc = "$where $name";
    my @items;
    foreach my $k (SortKeys($info)) {
        next if $dropTagKey{$k};
        my $v = $$info{$k};
        my $php;
        if ($k =~ /^(PrintConv|ValueConv|RawConv)$/) {
            $php = Conv($k, $v, \%sub, $loc);
        } elsif ($exprKey{$k} and defined $v and not ref $v) {
            $php = Code($k, $v, $loc);
        } elsif ($k eq 'IsOffset' and defined $v and not ref $v and not IsNumber($v)) {
            $php = Code('IsOffset', $v, $loc);
        } elsif ($k eq 'Format' and defined $v and not ref $v and $v =~ /^(.*)\[(.*)\]$/ and not IsNumber($2)) {
            push @items, "'FormatCount' => " . Code('FormatCount', $2, $loc);
            $php = PhpString($v);
        } elsif ($k eq 'SubDirectory' and ref $v eq 'HASH') {
            my @s;
            foreach my $sk (SortKeys($v)) {
                next if $dropSubdirKey{$sk};
                my $sv = $$v{$sk};
                if ($subdirExprKey{$sk} and defined $sv and not ref $sv) {
                    push @s, PhpKey($sk) . ' => ' . (IsNumber($sv) ? NumOf($sv) : Code($sk, $sv, $loc));
                } elsif (ref $sv eq 'CODE') {
                    push @s, PhpKey($sk) . ' => ' . CodeRef($sv, $sk eq 'ProcessProc' ? 'code' : "code", $loc);
                } elsif ($sk eq 'ProcessProc' and SubName($sv)) {
                    push @s, PhpKey($sk) . ' => ' . SubName($sv);
                } else {
                    push @s, PhpKey($sk) . ' => ' . Data($sv, { %sub, depth => $depth + 2 }, 0, $loc);
                }
            }
            $php = "[\n" . join('', map { "$pad    $_,\n" } @s) . "$pad]";
        } elsif (ref $v eq 'CODE') {
            $php = CodeRef($v, 'code', "$loc $k");
        } else {
            $php = Data($v, \%sub, 0, "$loc/$k");
        }
        push @items, PhpKey($k) . " => $php";
    }
    return "[\n" . join('', map { "$pad$_,\n" } @items) . "$end]";
}

sub Table
{
    my ($tname, $t) = @_;
    my @items;
    my %ind = (depth => 3);
    foreach my $k (SortKeys($t)) {
        my $v = $$t{$k};
        if ($TagTableSpecial{$k}) {
            next if $dropTableKey{$k};
            my $php;
            if ($k eq 'PRINT_CONV') {
                $php = Conv('PrintConv', $v, \%ind, "$tname PRINT_CONV");
            } elsif (ref $v eq 'CODE') {
                $php = CodeRef($v, 'code', "$tname $k");
            } elsif ($k =~ /_PROC$/ and SubName($v)) {
                $php = SubName($v);
            } else {
                $php = Data($v, \%ind, 0, "$tname $k");
            }
            push @items, PhpKey($k) . " => $php";
            next;
        }
        next if $tname eq 'Image::ExifTool::Composite';   # filled at run time by AddCompositeTags
        my $where = sprintf("%s %s", $tname, $k =~ /^\d+$/ ? sprintf('0x%.4x', $k) : $k);
        if (ref $v eq 'ARRAY') {
            my $pad = '            ';
            push @items, PhpKey($k) . " => [\n" . join('', map { "$pad    " . TagInfo($_, { depth => 4 }, $where) . ",\n" } @$v) . "$pad]";
        } else {
            push @items, PhpKey($k) . ' => ' . TagInfo($v, \%ind, $where);
        }
    }
    return "[\n" . join('', map { "            $_,\n" } @items) . "        ]";
}

# count references to each hash/array in a module, to emit shared structures once
sub CountRefs
{
    my ($v, $seen) = @_;
    return unless ref $v eq 'HASH' or ref $v eq 'ARRAY';
    return if ++$sharedCount{$v} > 1;
    if (ref $v eq 'HASH') {
        CountRefs($_) foreach values %$v;
    } else {
        CountRefs($_) foreach @$v;
    }
}

#------------------------------------------------------------------------------
# write the files
my $out = "$project/tables";
# (other processes may read these files while we run: write to temporary files, then rename)
my @written;
sub Out
{
    my $path = shift;
    open my $fh, '>', "$path.tmp$$" or die "$path: $!";
    push @written, $path;
    return $fh;
}
make_path("$out/vars", "$out/_todo");
unlink glob("$out/_todo/*.txt");
my %modules;
$modules{ModuleKey($_)}{$_} = 1 foreach keys %tables;
$modules{ModuleKey("${_}::x")} ||= {} foreach keys %varsOf;
$modules{ModuleKey("${_}::x")} ||= {} foreach keys %pkgSubs;
my (%index, $nTodo, $nCode);
my $header = "// \@generated by tools/export-tables.pl from Image::ExifTool $version, do not edit\n";

foreach $mod (sort keys %modules) {
    next if $skipModule{$mod};
    (%closures, @closureOrder, %todo, %shared, @sharedOrder, %sharedCount) = ();
    $nShared = 0;
    my @names = sort keys %{$modules{$mod}};
    CountRefs($tables{$_}) foreach @names;
    # tag info hashes and tables are emitted inline (never shared)
    foreach my $t (@names) {
        foreach my $v (values %{$tables{$t}}) {
            delete $sharedCount{$v} if ref $v eq 'HASH' or ref $v eq 'ARRAY';
            if (ref $v eq 'ARRAY') { delete $sharedCount{$_} foreach @$v }
            if (ref $v eq 'HASH' and ref $$v{SubDirectory}) { delete $sharedCount{$$v{SubDirectory}} }
        }
        delete $sharedCount{$tables{$t}};
    }
    my @tableCode = map { "        " . PhpString($_) . " => " . Table($_, $tables{$_}) . ",\n" } @names;
    $index{$_} = $mod foreach @names;
    my $class = ClassName($mod);

    if (@names or $loadActions{$mod}) {
        my $fh = Out("$out/$mod.php");
        print $fh "<?php\n\n$header\nnamespace Image\\ExifTool;\n\n";
        foreach my $id (@closureOrder) {
            print $fh "\$f_$id = $closures{$id};\n";
        }
        print $fh "\n" if @closureOrder;
        foreach my $v (@sharedOrder) {
            print $fh "$shared{$v}[0] = $shared{$v}[1];\n";
        }
        print $fh "\n" if @sharedOrder;
        my $actions = join '', map { "        [" . join(', ', map { PhpScalar($_) } @$_) . "],\n" } @{$loadActions{$mod} || []};
        print $fh "return [\n    'load' => [\n$actions    ],\n    'tables' => [\n", @tableCode, "    ],\n];\n";
        close $fh;
    }
    if (%todo) {
        my $fh = Out("$out/_todo/$mod.txt");
        foreach my $id (sort { $todo{$a}[0] cmp $todo{$b}[0] or $a cmp $b } keys %todo) {
            my ($context, $src, $where, $extra) = @{$todo{$id}};
            my $sigKey = $context =~ /^code/ ? $context : $context;
            print $fh "==== $id $context\n";
            print $fh "# at: $where\n";
            print $fh "# signature: $$sigs{$sigKey}\n" if $$sigs{$sigKey};
            if ($extra and $$extra{vars} and @{$$extra{vars}}) {
                print $fh "# lexicals: $_\n" foreach @{$$extra{vars}};
            }
            if ($extra and $$extra{scalars} and %{$$extra{scalars}}) {
                print $fh "# closed-over scalars (take them with use (...)): ", join(', ', sort keys %{$$extra{scalars}}), "\n";
            }
            (my $perl = $src) =~ s/^/# | /mg;
            print $fh "$perl\n\n";
            ++$nTodo;
        }
        close $fh;
    }
    $nCode += @closureOrder;
}

# Vars classes: package variables + file lexicals closed over by the module's subs
foreach my $pkg (sort keys %pkgSubs) {
    foreach my $name (sort keys %{$pkgSubs{$pkg}}) {
        my $closed = PadWalker::closed_over($pkgSubs{$pkg}{$name});
        foreach my $v (sort keys %$closed) {
            next unless $v =~ /^[%\@]/ or ($v =~ /^\$/ and not ref ${$$closed{$v}});
            LexicalName($pkg, $v, $$closed{$v});
        }
    }
}
my %varsByMod;
# (deterministic names on collisions: $x, then @x, then %x_h; lexicals in name order)
my %sigilOrder = ('$' => 0, '@' => 1, '%' => 2);
foreach my $pkg (sort keys %varsOf) {
    my $m = ModuleKey("${pkg}::x");
    foreach my $v (sort { substr($a, 1) cmp substr($b, 1) or $sigilOrder{substr($a, 0, 1)} <=> $sigilOrder{substr($b, 0, 1)} } keys %{$varsOf{$pkg}}) {
        (my $name = $v) =~ s/^[\$\@%]//;
        $name .= '_' . ($v =~ /^%/ ? 'h' : $v =~ /^\@/ ? 'a' : 's') if $varsByMod{$m}{$name};
        $varsByMod{$m}{$name} = [ $v, $varsOf{$pkg}{$v}, 'package' ];
    }
}
foreach my $m (sort keys %lexicals) {
    foreach my $name (sort keys %{$lexicals{$m}}) {
        my ($perlName, $ref) = @{$lexicals{$m}{$name}};
        my $n = $name;
        $n .= '_lex' while $varsByMod{$m}{$n} and $varsByMod{$m}{$n}[1] != $ref;
        $varsByMod{$m}{$n} = [ $perlName, $ref, 'lexical' ];
    }
}
foreach my $m (sort keys %varsByMod) {
    next if $skipModule{$m};
    (%shared, @sharedOrder, %sharedCount) = ();
    my $class = ClassName($m);
    my $fh = Out("$out/vars/$class.php");
    print $fh "<?php\n\n$header\nnamespace Image\\ExifTool\\Vars;\n\n";
    print $fh "/** Package variables and file lexicals of " . ($m eq 'ExifTool' ? 'Image::ExifTool' : "Image::ExifTool::$m") . " */\n";
    print $fh "final class $class\n{\n";
    foreach my $name (sort keys %{$varsByMod{$m}}) {
        my ($perlName, $ref, $kind) = @{$varsByMod{$m}{$name}};
        next if ref $ref eq 'HASH' and $tables{"Image::ExifTool::" . ($m eq 'ExifTool' ? '' : "$m\::") . substr($perlName, 1)};
        my $val = $perlName =~ /^\$/ ? (ref $ref eq 'SCALAR' || ref $ref eq 'REF' ? $$ref : $ref) : $ref;
        next if $perlName =~ /^\$/ and ref $val and ref $val ne 'HASH' and ref $val ne 'ARRAY';
        my $php = Data($val, { depth => 1 }, 1, "$m $perlName");
        print $fh "    /** $kind $perlName */\n    public static \$$name = $php;\n\n";
    }
    print $fh "}\n";
    close $fh;
}

# character set maps: one class each, loaded on demand
make_path("$out/vars/Charset");
foreach my $cs (sort keys %charsets) {
    (%shared, @sharedOrder, %sharedCount) = ();
    my $fh = Out("$out/vars/Charset/$cs.php");
    print $fh "<?php\n\n$header\nnamespace Image\\ExifTool\\Vars\\Charset;\n\n";
    print $fh "/** %Image::ExifTool::Charset::$cs */\nfinal class $cs\n{\n    public static \$map = " . Data($charsets{$cs}, { depth => 1 }, 1, "Charset $cs") . ";\n}\n";
    close $fh;
}

my $fh = Out("$out/_index.php");
print $fh "<?php\n\n$header\ndeclare(strict_types=1);\n\nreturn [\n";
print $fh "    " . PhpString($_) . " => " . PhpString($index{$_}) . ",\n" foreach sort keys %index;
print $fh "];\n";
close $fh;

close $fh;
foreach my $path (@written) {
    rename("$path.tmp$$", $path) or die "rename $path: $!";
}

printf STDERR "ExifTool %s: %d tables in %d modules, %d code pieces, %d translated, %d to do\n",
    $version, scalar(keys %index), scalar(keys %modules), $nCode, $nCode - ($nTodo || 0), $nTodo || 0;
