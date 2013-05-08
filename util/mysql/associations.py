import MySQLdb as mdb
import sys, bz2
import xml.parsers.expat as expat
import config

def ToObjectCode(objType):
	if objType == "node": return 0;
	if objType == "way": return 1;
	if objType == "relation": return 2;
	raise Exception("Unrecognised type "+str(objType))

class AssociationParser:

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
		self.FlushBuffer()
		self.con.commit()
		
	def Parse(self, data, done):
		self._parser.Parse(data, done)

	def FlushBuffer(self):
		if len(self.buffer) == 0: return
			
		sql = "INSERT INTO "+self.dbName;
		sql += ".assoc (ptype, pid, pver, ctype, cid, role) VALUES "

		for recnum, rec in enumerate(self.buffer):
			sql += "({0},{1},{2},{3},{4},'{5}')".format(rec['objectType'], \
				rec['objectId'], rec['objectVer'], \
				ToObjectCode(rec['type']), int(rec['ref']),\
				self.con.escape_string(rec['role'].encode("UTF-8")))
			if recnum < len(self.buffer)-1: sql += ","

		sql += ";";
		#print sql
		self.cur.execute(sql)
		self.count += len(self.buffer)
		self.buffer = []
		self.con.commit()

	def start(self, tag, attrs):
		#print "START", repr(tag), attrs
		self.depth += 1

		if tag == "bound": return
		if tag == "changeset": return

		if self.depth == 2:
			self.objectType = ToObjectCode(tag)
			self.objectId = int(attrs['id'])
			self.objectVer = int(attrs['version'])

			if self.objectType not in self.countObjs:
				self.countObjs[self.objectType] = 0
			self.countObjs[self.objectType] += 1

		if self.depth == 3 and self.objectType!=None:
			
			if tag == "nd":
				#print repr(tag), attrs
				record = {'objectType':1,'objectId':self.objectId,\
					'objectVer':self.objectVer,'type':'node',\
					'ref':attrs['ref'],'role':''}
				self.buffer.append(record)

			if tag == "member":
				#print tag, attrs
				record = {'objectType':self.objectType,'objectId':self.objectId,\
					'objectVer':self.objectVer,'type':attrs['type'],\
					'ref':attrs['ref'],'role':attrs['role']}
				self.buffer.append(record)

			if len(self.buffer) >= 1000:
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

		sql = "DROP TABLE IF EXISTS "+config.dbName+".assoc;"
		cur.execute(sql)

		sql = "CREATE TABLE IF NOT EXISTS "+config.dbName+".assoc (intid BIGINT PRIMARY KEY AUTO_INCREMENT, ptype INTEGER, pid BIGINT, pver BIGINT, ctype INTEGER, cid BIGINT, role TEXT, INDEX(pid, ptype)) DEFAULT CHARACTER SET utf8 COLLATE utf8_bin ENGINE=MyISAM;";
		cur.execute(sql)

		inFinaXml = bz2.BZ2File(config.fina, 'r')

		#Read text file into expat parser	
		reading = 1
		parser = AssociationParser(con)
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

