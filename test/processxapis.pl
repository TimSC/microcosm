use strict;
use warnings;
open IN,"test/xapis.txt" or die;
while (<IN>)
{
    my $pred=$_;
    my $cmd="php5 kml.php  \"-p:/0.6/$pred\" 2>&1 |";
    open CMD, $cmd;
    while (<CMD>){
        warn $_;
    }
    close CMD;
}

close IN;
