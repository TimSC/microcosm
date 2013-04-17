import MySQLdb as mdb
import sys, bz2
import xml.parsers.expat as expat

def ToObjectCode(objType):
	if objType == "node": return 0;
	if objType == "way": return 1;
	if objType == "relation": return 2;
	raise Exception("Unrecognised type")

class TagParser:

	def __init__(self, con):
		self._parser = expat.ParserCreate()
		self._parser.StartElementHandler = self.start
		self._parser.EndElementHandler = self.end
		self.depth = 0
		self.objectType = None
		self.objectId = None
		self.objectVer = None
		self.dbName = "map"
		self.count = 0
		self.countObjs = {}
		self.con = con
		self.cur = self.con.cursor()

	def __del__(self):
		self.con.commit()
		
	def Parse(self, data, done):
		self._parser.Parse(data, done)

	def start(self, tag, attrs):
		#print "START", repr(tag), attrs
		self.depth += 1

		if self.depth == 2:
			self.objectType = ToObjectCode(tag)
			self.objectId = int(attrs['id'])
			self.objectVer = int(attrs['version'])

			if self.objectType not in self.countObjs:
				self.countObjs[self.objectType] = 0
			self.countObjs[self.objectType] += 1

			#print repr(tag), attrs
			changeset = int(attrs['changeset'])
			user = attrs['user']
			uid = int(attrs['uid'])
			timestamp = attrs['timestamp']

			test = ".meta (type, id, ver, changeset, user, uid, timestamp) VALUES ({0},{1},{2},{3},'{4}',{5},'{6}');"\
				.format(self.objectType, self.objectId, self.objectVer, changeset, \
				self.con.escape_string(user.encode("UTF-8")), uid, \
				self.con.escape_string(timestamp.encode("UTF-8")))
			sql = "INSERT INTO "+self.dbName+test;
			#print sql
			self.cur.execute(sql)
			self.count += 1
			if self.count % 1000 == 0:
				self.con.commit()
				print self.count, self.countObjs


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

		sql = "DROP TABLE IF EXISTS "+dbName+".meta;"
		cur.execute(sql)

		sql = "CREATE TABLE IF NOT EXISTS "+dbName+".meta (intid BIGINT PRIMARY KEY AUTO_INCREMENT, type INTEGER, id BIGINT, ver BIGINT, changeset BIGINT, user TEXT, uid BIGINT, timestamp TEXT, visible INTEGER, INDEX(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		print sql
		cur.execute(sql)

		inFina = "/home/tim/Downloads/northern_mariana_islands.osm.bz2"
		inFinaXml = bz2.BZ2File(inFina,'r')

		#Read text file into expat parser	
		reading = 1
		parser = TagParser(con)
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

