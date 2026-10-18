#!/usr/bin/perl
#------------------------------------------------------------------------------
# Perl twin of tests/new-values.php: runs the same new-value operations (SetNewValue,
# GetNewValue, SetNewValuesFromFile, Save/RestoreNewValues...) with Image::ExifTool and
# prints the results as JSON.  Reads the cases (JSON) on stdin.
# Run by tests/new-values.php: perl -w -I ~/exiftool/lib tests/new-values.pl < cases.json
#------------------------------------------------------------------------------
use strict;
use Image::ExifTool;
use JSON::PP;
use Digest::MD5 qw(md5_hex);

binmode STDIN;
binmode STDOUT;
my $cases = JSON::PP->new->decode(do { local $/; <STDIN> });

# escape the bytes of a string so the JSON stays plain ASCII
sub Esc
{
    my $s = shift;
    $s =~ s/([\\\x00-\x1f\x7f-\xff])/sprintf('\\x%.2x', ord $1)/ge;
    return $s;
}

# serialize a value
sub Ser
{
    my $v = shift;
    return undef unless defined $v;
    my $r = ref $v;
    unless ($r) {
        my $s = "$v";
        return length($s) > 300 ? 'LONG:' . length($s) . ':' . md5_hex($s) : Esc($s);
    }
    return [ map { Ser($_) } @$v ] if $r eq 'ARRAY';
    return { map { Esc($_) => Ser($$v{$_}) } keys %$v } if $r eq 'HASH';
    return { SCALAR => Ser($$v) } if $r eq 'SCALAR';
    return "REF($r)";
}

# decode a value from the cases ({__hex} = bytes, {__sref} = scalar reference)
sub Val
{
    my $v = shift;
    if (ref $v eq 'HASH') {
        return pack('H*', $$v{__hex}) if exists $$v{__hex};
        if (exists $$v{__sref}) { my $s = Val($$v{__sref}); return \$s; }
        return { map { $_ => Val($$v{$_}) } keys %$v };
    }
    return [ map { Val($_) } @$v ] if ref $v eq 'ARRAY';
    return $v;
}

# dump the new value hashes of a NEW_VALUE or SAVE_NEW_VALUE hash
sub DumpNV
{
    my $hash = shift;
    my @nv;
    foreach my $key (keys %{$hash || {}}) {
        my $pos = 0;
        for (my $nvHash = $$hash{$key}; $nvHash; $nvHash = $$nvHash{Next}) {
            my $ti = $$nvHash{TagInfo};
            my %d = (
                Tag => $$ti{Name},
                ID => Esc("$$ti{TagID}" . (defined $$ti{Index} ? ".$$ti{Index}" : '')),
                Table => $$ti{Table}{TABLE_NAME},
                Pos => $pos++,
                Head => $$hash{$key}{Order},
            );
            foreach (qw(WriteGroup WantGroup IsCreating CreateOnly EditOnly NoReplace AddBefore Shift Save Order)) {
                $d{$_} = Ser($$nvHash{$_}) if defined $$nvHash{$_};
            }
            $d{Value} = Ser($$nvHash{Value}) if $$nvHash{Value};
            $d{DelValue} = Ser($$nvHash{DelValue}) if $$nvHash{DelValue};
            $d{CreateGroups} = [ sort keys %{$$nvHash{CreateGroups}} ] if $$nvHash{CreateGroups};
            push @nv, \%d;
        }
    }
    # (compare copies: a string used as a number would be encoded as a number by JSON::PP)
    return [ sort { my ($x, $y) = ($$a{Order}, $$b{Order}); $x <=> $y or $$a{Tag} cmp $$b{Tag} } @nv ];
}

my @out;
my $i = 0;
foreach my $case (@$cases) {
    my $warnings = '';
    # (run-time warnings of -w from the library code itself aren't emulated by the port, and
    #  the location of the other warnings isn't comparable)
    local $SIG{__WARN__} = sub {
        my $w = shift;
        return if $w =~ /^(Use of uninitialized value|Argument ".*" isn't numeric)/;
        $w =~ s/ at \S+ line \d+(, <\w+> line \d+)?\.\n$/\n/;
        $warnings .= $w;
    };
    my $et = Image::ExifTool->new;
    my $verbose = '';
    open my $vfh, '>', \$verbose;
    $et->Options(TextOut => $vfh);
    my @res;
    foreach my $op (@{$$case{ops}}) {
        my ($what, @a) = @$op;
        my $r;
        my $o = Val($a[2]);
        my %opts = ref $o eq 'HASH' ? %$o : ();
        if ($what eq 'set') {
            $r = [ $et->SetNewValue($a[0], Val($a[1]), %opts) ];
            $$r[1] = undef if @$r < 2;
        } elsif ($what eq 'sset') {
            $r = scalar $et->SetNewValue($a[0], Val($a[1]), %opts);
        } elsif ($what eq 'opt') {
            $et->Options(@{Val(\@a)});
            $r = 1;
        } elsif ($what eq 'get') {
            my $nvHash;
            my $s = $et->GetNewValue($a[0]);
            my @l = $et->GetNewValue($a[0], \$nvHash);
            $r = [ $s, \@l, $nvHash ? $$nvHash{WriteGroup} : undef ];
        } elsif ($what eq 'count') {
            $r = [ $et->CountNewValues() ];
        } elsif ($what eq 'save') {
            $r = $et->SaveNewValues();
        } elsif ($what eq 'restore') {
            $et->RestoreNewValues();
            $r = 1;
        } elsif ($what eq 'from') {
            $r = $et->SetNewValuesFromFile(@{Val(\@a)});
        } elsif ($what eq 'over') {
            my $nvHash;
            $et->GetNewValue($a[0], \$nvHash);
            my $o = $et->IsOverwriting($nvHash, Val($a[1]));
            $r = [ $o, [ $et->GetNewValue($nvHash) ] ];
        } elsif ($what eq 'writable') {
            $r = Image::ExifTool::IsWritable($a[0]);
        } elsif ($what eq 'geo') {
            $r = [ $et->GetGeolocateTags($a[0], $a[1]) ];
        } elsif ($what eq 'idt') {
            $r = $et->InverseDateTime($a[0], $a[1], $a[2]);
        } elsif ($what eq 'rev') {
            my @r = Image::ExifTool::ReverseLookup($a[0], Val($a[1]));
            $r = [ @r[0, 1] ];  # (a list of 2 even after "return undef")
        } elsif ($what eq 'bits') {
            my @r = Image::ExifTool::EncodeBits($a[0], Val($a[1]), $a[2], $a[3]);
            $r = [ @r[0, 1] ];
        } elsif ($what eq 'check') {
            my $val = Val($a[0]);
            my $err = Image::ExifTool::CheckValue(\$val, $a[1], $a[2]);
            $r = [ $err, $val ];
        } elsif ($what eq 'alt') {
            $r = $et->SetAlternateFile($a[0], $a[1]);
        } elsif ($what eq 'groups') {
            $et->SetNewGroups(@a) if @a;
            $r = [ $et->GetNewGroups() ];
        } elsif ($what eq 'nvlist') {
            $r = [ sort map { $$_{Name} } $et->GetNewTagInfoList() ];
        } elsif ($what eq 'shift') {
            my $nvHash;
            $et->GetNewValue($a[0], \$nvHash);
            my $err = $et->ApplyShift($a[1], $a[2], $a[3], $nvHash);
            $r = [ $err, [ $et->GetNewValue($nvHash) ] ];
        } elsif ($what eq 'require') {
            eval "require $a[0]";
            $r = 1;
        } elsif ($what eq 'udt') {
            $r = Image::ExifTool::AddUserDefinedTags($a[0], %{Val($a[1])});
        } else {
            die "unknown op $what\n";
        }
        push @res, Ser($r);
    }
    my %warn = map { Esc($_) => Ser($$et{VALUE}{$_}) } grep /^Warning/, keys %{$$et{VALUE}};
    push @out, {
        res => \@res,
        nv => DumpNV($$et{NEW_VALUE}),
        save => DumpNV($$et{SAVE_NEW_VALUE}),
        del => { map { Esc($_) => $$et{DEL_GROUP}{$_} } keys %{$$et{DEL_GROUP}} },
        count => [ $et->CountNewValues() ],
        warn => \%warn,
        stderr => Esc($warnings),
        verbose => Esc($verbose),
    };
    ++$i;
}
print JSON::PP->new->canonical->encode(\@out);
