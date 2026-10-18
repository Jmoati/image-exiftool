#!/usr/bin/perl
#------------------------------------------------------------------------------
# Perl twin of the PHP runner of tools/write-parity.php: applies a subset of the exiftool
# write arguments through the Image::ExifTool API (SetNewValue + WriteInfo, the way the
# exiftool script does it) and prints the result of every call.
#
#   perl tools/write-parity.pl EXIFTOOL_LIB [OPTIONS] [-TAG[+-^]=[VALUE]...] [-TAG<=DATAFILE] FILE
#
# OPTIONS: -o OUTFILE, -m, -n, -v[NUM], -api OPT[=VAL], -use MWG, -overwrite_original (no -o: edit FILE
# in place), and how FILE and OUTFILE are passed to WriteInfo: -_memin (data in memory, a scalar
# reference), -_memout (to a scalar reference, then saved), -_handle (a file handle),
# -_handleout (OUTFILE as a file handle), -_raf (a File::RandomAccess object)
# Keep in sync with Runner() in tools/write-parity.php.
#------------------------------------------------------------------------------
use strict;
use warnings;

my $lib = shift or die "usage: $0 EXIFTOOL_LIB ARGS... FILE\n";
unshift @INC, $lib;
require Image::ExifTool;

my $et = Image::ExifTool->new;
my ($outfile, @files, @newValues, %api, $useMWG);
while (@ARGV) {
    my $arg = shift @ARGV;
    if ($arg eq '-o') {
        $outfile = shift @ARGV;
    } elsif ($arg eq '-m') {
        $et->Options(IgnoreMinorErrors => 1);
    } elsif ($arg eq '-n') {
        $et->Options(PrintConv => 0);
    } elsif ($arg =~ /^-v(\d*)$/) {
        $et->Options(Verbose => length $1 ? $1 : 1);
    } elsif ($arg eq '-api') {
        my ($opt, $val) = split /=/, shift(@ARGV), 2;
        $et->Options($opt => defined $val ? $val : 1);
    } elsif ($arg eq '-overwrite_original') {
        # (the API never keeps a backup)
    } elsif ($arg eq '-use') {
        $useMWG = (lc(shift @ARGV) eq 'mwg');
    } elsif ($arg =~ /^-_(memin|memout|handle|handleout|raf)$/) {
        $api{$1} = 1;   # (not exiftool options: how the files are passed to WriteInfo)
    } elsif ($arg =~ /^-(.*=.*)$/s) {
        push @newValues, $1;
    } else {
        push @files, $arg;
    }
}
# load the MWG module if used (-use MWG or MWG: tags), like the exiftool script
if ($useMWG or grep /^([-_0-9A-Z]+:)*1?mwg:/i, @newValues) {
    require Image::ExifTool::MWG;
    Image::ExifTool::MWG::Load();
    $et->Options(CharsetEXIF => 'UTF8') unless defined $et->Options('CharsetEXIF');
}
# set the new values like the exiftool script does
my %addDelOpt = ( '+' => 'AddValue', '-' => 'DelValue', "\xe2\x88\x92" => 'DelValue' );
foreach (@newValues) {
    /(.*?)=(.*)/s or next;
    my ($tag, $newVal) = ($1, $2);
    $tag =~ s/\ball\b/\*/ig;    # replace 'all' with '*' in tag names
    $newVal eq '' and undef $newVal unless $tag =~ s/\^([-+]*)$/$1/;  # undefined to delete tag
    my %opts = ( Shift => 0 );  # shift values if possible instead of adding/deleting
    # allow writing of 'Unsafe' tags unless specified by wildcard
    $opts{Protected} = 1 unless $tag =~ /[?*]/;
    if ($tag =~ s/<// and defined $newVal) {
        # read file data into $newVal (-TAG<=FILE)
        open my $in, '<', $newVal or print("Error opening file $newVal\n"), next;
        binmode $in;
        local $/;
        $newVal = <$in>;
        close $in;
    }
    if ($tag =~ s/([-+]|\xe2\x88\x92)$//) {
        $opts{$addDelOpt{$1}} = 1;  # set AddValue or DelValue option
        # set $newVal to '' if deleting nothing
        $newVal = '' if $1 eq '-' and not defined $newVal;
    }
    my ($rtn, $wrn) = $et->SetNewValue($tag, $newVal, %opts);
    print "SetNewValue $tag: $rtn", ($wrn ? " ($wrn)" : ''), "\n";
}
# pass the files as the API options ask
my ($src, $dst, $outBuff, $fh) = ($files[0], $outfile);
if ($api{memin} or $api{raf}) {
    open $fh, '<', $src or die "can't read $src\n";
    binmode $fh;
    local $/;
    my $buff = <$fh>;
    close $fh;
    $src = \$buff;
    if ($api{raf}) {
        require File::RandomAccess;
        $src = File::RandomAccess->new($src);
    }
} elsif ($api{handle}) {
    open $fh, '+<', $src or die "can't open $src\n";
    binmode $fh;
    $src = $fh;
}
my $outFh;
if ($api{memout}) {
    $dst = \$outBuff;
} elsif ($api{handleout}) {
    open $outFh, '>', $outfile or die "can't create $outfile\n";
    binmode $outFh;
    $dst = $outFh;
}
my $rtn = $et->WriteInfo($src, $dst);
print "WriteInfo: $rtn\n";
if ($api{memout} and defined $outBuff and defined $outfile) {
    open my $out, '>', $outfile or die;
    binmode $out;
    print $out $outBuff;
    close $out;
} elsif ($api{memin} and not defined $outfile) {
    open my $out, '>', $files[0] or die;
    binmode $out;
    print $out $$src;
    close $out;
}
close $fh if $api{handle};
close $outFh if $api{handleout};
my $info = $et->GetInfo('Warning', 'Error');
print "$_: $$info{$_}\n" foreach sort keys %$info;
