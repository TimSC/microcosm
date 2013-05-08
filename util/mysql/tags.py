import MySQLdb as mdb
import sys, bz2
import xml.parsers.expat as expat
import config

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

	def FlushBuffer(self):
		if len(self.buffer) == 0: return
		#print repr(tag), attrs
		sql = "INSERT INTO "+self.dbName
		sql += ".tags (type, id, ver, k, v) VALUES "
		for recnum, rec in enumerate(self.buffer):
			sql += "({0},{1},{2},'{3}','{4}')".format(rec['objectType'], rec['objectId'], \
				rec['objectVer'], \
				self.con.escape_string(rec['k'].encode("UTF-8")), \
				self.con.escape_string(rec['v'].encode("UTF-8")))
			if recnum < len(self.buffer)-1: sql += ","

		sql += ";"
		self.cur.execute(sql)
		self.count += len(self.buffer)
		self.con.commit()
		self.buffer = []

	def start(self, tag, attrs):

		self.depth += 1
		#print "START", self.depth, repr(tag)

		if tag == "bound": return
		if tag == "changeset": return

		if self.depth == 2:
			self.objectType = ToObjectCode(tag)
			self.objectId = int(attrs['id'])
			self.objectVer = int(attrs['version'])

			if self.objectType not in self.countObjs:
				self.countObjs[self.objectType] = 0
			self.countObjs[self.objectType] += 1

		if tag == "tag" and self.depth == 3 and self.objectType!=None:
			if attrs['k'] != 'created_by':

				record = {'objectType':self.objectType,'objectId':self.objectId,\
					'objectVer':self.objectVer,'k':attrs['k'],'v':attrs['v']}
				self.buffer.append(record)
				if len(self.buffer) > 1000:
					self.FlushBuffer()
					print self.count, self.countObjs



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

		sql = "DROP TABLE IF EXISTS "+config.dbName+".tags;"
		cur.execute(sql)

		sql = "CREATE TABLE IF NOT EXISTS "+config.dbName+".tags (intid BIGINT PRIMARY KEY AUTO_INCREMENT, type INTEGER, id BIGINT, ver BIGINT, k VARCHAR(255), v VARCHAR(255)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		print sql
		cur.execute(sql)
		print "Create table done"

		inFinaXml = bz2.BZ2File(config.fina, 'r')

		#Read text file into expat parser	
		reading = 1
		parser = TagParser(con)
		while reading:
			xmlTxt = inFinaXml.read(config.pageSize)
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

