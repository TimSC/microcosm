import MySQLdb as mdb
import sys, bz2
import xml.parsers.expat as expat

class Parser:

	def __init__(self):
		self._parser = expat.ParserCreate()
		self._parser.StartElementHandler = self.start
		self._parser.EndElementHandler = self.end
		self.depth = 0
		self.objectType = None
		self.objectId = None
		self.objectVer = None

	def Parse(self, data, done):
		self._parser.Parse(data, done)

	def start(self, tag, attrs):
		#print "START", repr(tag), attrs
		self.depth += 1

		if self.depth == 2:
			self.objectType = tag
			self.objectId = int(attrs['id'])
			self.objectVer = int(attrs['version'])

		if tag == "node" and self.depth == 2:
			changeset = int(attrs['changeset'])
			uid = int(attrs['uid'])
			timestamp = attrs['timestamp']
			lon = float(attrs['lon'])
			lat = float(attrs['lat'])
			version = int(attrs['version'])
			user = attrs['user']
			uid = int(attrs[u'id'])

			print uid, lon, lat

	def end(self, tag):
		
		if self.depth == 2:
			self.objectType = None
			self.objectId = None
			self.objectVer = None

		#print "END", repr(tag)
		self.depth -= 1


if __name__ == "__main__":

	
	con = None

	try:

		dbName = "map"
		con = mdb.connect('localhost', 'map', 'maptest222', dbName);
		cur = con.cursor()

		sql = "CREATE TABLE IF NOT EXISTS "+dbName+".geom (intid BIGINT PRIMARY KEY, g GEOMETRY NOT NULL, SPATIAL INDEX(g), type INTEGER, id BIGINT, ver BIGINT, INDEX(id,ver)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		cur.execute(sql)

		inFina = "/home/tim/Downloads/northern_mariana_islands.osm.bz2"
		inFinaXml = bz2.BZ2File(inFina,'r')

		#Read text file into expat parser	
		reading = 1
		parser = Parser()
		while reading:
			xmlTxt = inFinaXml.read(1024 * 1024)
			if len(xmlTxt) > 0:
				parser.Parse(xmlTxt, 0)
			else:
				reading = 0
		parser.Parse("",1)


	except mdb.Error, e:
	  
		print "Error %d: %s" % (e.args[0],e.args[1])
		sys.exit(1)
		
	finally:	
			
		if con:	
			con.close()

