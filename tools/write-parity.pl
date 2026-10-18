#!/usr/bin/perl
#------------------------------------------------------------------------------
# Perl twin of the PHP runner of tools/write-parity.php: applies a subset of the exiftool
# write arguments through the Image::ExifTool API (SetNewValue + WriteInfo, the way the
# exiftool script does it) and prints the result of every call.
#
#   perl tools/write-parity.pl EXIFTOOL_LIB [OPTIONS] [-TAG[+-^]=[VALUE]...] FILE
#
# OPTIONS: -o OUTFILE, -m, -n, -v[NUM], -api OPT[=VAL], -overwrite_original (no -o: edit FILE
# in place)
# Keep in sync with Runner() in tools/write-parity.php.
#------------------------------------------------------------------------------
use strict;
use warnings;

my $lib = shift or die "usage: $0 EXIFTOOL_LIB ARGS... FILE\n";
unshift @INC, $lib;
require Image::ExifTool;

my $et = Image::ExifTool->new;
my ($outfile, @files, @newValues);
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
    } elsif ($arg =~ /^-(.*=.*)$/s) {
        push @newValues, $1;
    } else {
        push @files, $arg;
    }
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
    if ($tag =~ s/([-+]|\xe2\x88\x92)$//) {
        $opts{$addDelOpt{$1}} = 1;  # set AddValue or DelValue option
        # set $newVal to '' if deleting nothing
        $newVal = '' if $1 eq '-' and not defined $newVal;
    }
    my ($rtn, $wrn) = $et->SetNewValue($tag, $newVal, %opts);
    print "SetNewValue $tag: $rtn", ($wrn ? " ($wrn)" : ''), "\n";
}
my $rtn = $et->WriteInfo($files[0], $outfile);
print "WriteInfo: $rtn\n";
my $info = $et->GetInfo('Warning', 'Error');
print "$_: $$info{$_}\n" foreach sort keys %$info;
