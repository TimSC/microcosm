unittest:
	phpunit test/ test/001_microcosm.php

# compile with hphp
all :
	HPHP_HOME=/usr/share/hphphome hphp -o hphpout  -l 4  -k 1 -t cpp compile.php
#	HPHP_HOME=/usr/share/hphphome hphp -o hphpout -k 1 -t cpp microcosm.php
#	hphp -o hphpout -k 1 -t cpp model-sqlite-opt.php
#	hphp -o hphpout -k 1 -t cpp modelfactory.php
#	hphp -o hphpout -k 1 -t cpp model-sqlite-opt.php


