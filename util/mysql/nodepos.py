import MySQLdb as mdb
import sys, bz2
import xml.parsers.expat as expat
import config, time

def ToObjectCode(objType):
	if objType == "node": return 0;
	if objType == "way": return 1;
	if objType == "relation": return 2;
	#raise Exception("Unrecognised type "+objType)
	return -1;

class NodePositionParser:

	def __init__(self, con):
		self._parser = expat.ParserCreate()
		self._parser.StartElementHandler = self.start
		self._parser.EndElementHandler = self.end
		self.depth = 0
		self.objectType = None
		self.objectId = None
		self.objectVer = None
		self.dbName = config.dbName
		self.count = 0
		self.countObjs = {}
		self.con = con
		self.cur = self.con.cursor()
		self.buffer = []

	def __del__(self):
		self.con.commit()
		
	def Parse(self, data, done):
		self._parser.Parse(data, done)

	def start(self, tag, attrs):
		#print "START", repr(tag), attrs
		self.depth += 1

		if tag == "bound": return
		if tag == "changeset": return

		if self.depth == 2:
			#print tag, attrs
			self.objectType = ToObjectCode(tag)
			self.objectId = int(attrs['id'])
			self.objectVer = int(attrs['version'])

			if self.objectType not in self.countObjs:
				self.countObjs[self.objectType] = 0
			self.countObjs[self.objectType] += 1

		if tag == "node" and self.depth == 2:
			#print tag, attrs
			changeset = int(attrs['changeset'])
			#uid = int(attrs['uid'])
			objectCode = ToObjectCode(tag)
			timestamp = attrs['timestamp']
			lon = float(attrs['lon'])
			lat = float(attrs['lat'])
			version = int(attrs['version'])
			#user = attrs['user']
			objId = int(attrs[u'id'])

			#print uid, lon, lat
			record = {'lat':lat,'lon':lon,'objectCode':objectCode,'objId':objId,'version':version}
			self.buffer.append(record)

			
			if len(self.buffer) > 1000:
				self.FlushBuffer()
				print time.clock(), self.count, self.countObjs

	def FlushBuffer(self):
		if len(self.buffer) == 0: return
		sql = "INSERT INTO "+self.dbName+".geom (g, type, id, ver) VALUES "
		for recnum, rec in enumerate(self.buffer):
			sql+="(GeomFromText('POINT("+str(rec['lat'])+" "+str(rec['lon'])+")'),"+str(rec['objectCode'])+","+str(rec['objId'])+","+str(rec['version'])+")"
			if recnum < len(self.buffer) - 1:
				sql += ","

		sql += ";";
		#print sql
		self.cur.execute(sql)
		self.count += len(self.buffer)
		self.buffer = []
		self.con.commit()

	def end(self, tag):
		
		if self.depth == 2:
			self.objectType = None
			self.objectId = None
			self.objectVer = None

		if self.depth == 1:
			self.FlushBuffer()

		#print "END", repr(tag)
		self.depth -= 1

if __name__ == "__main__":

	
	con = None

	try:

		con = mdb.connect(config.hostname, config.username, \
			config.password, config.dbName);
		cur = con.cursor()

		sql = "DROP TABLE IF EXISTS "+config.dbName+".geom;"
		cur.execute(sql)

		sql = "CREATE TABLE IF NOT EXISTS "+config.dbName+".geom (intid BIGINT PRIMARY KEY AUTO_INCREMENT, g GEOMETRY NOT NULL, type INTEGER, id BIGINT, ver BIGINT) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		cur.execute(sql)

		inFinaXml = bz2.BZ2File(config.fina, 'r')

		#Read text file into expat parser	
		reading = 1
		parser = NodePositionParser(con)
		while reading:
			xmlTxt = inFinaXml.read(config.pageSize)
			if len(xmlTxt) > 0:
				#print len(xmlTxt)
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

