import bz2

inData = bz2.BZ2File("/var/www/ireland.osm.bz2")

for line in inData.readlines():
	print line

