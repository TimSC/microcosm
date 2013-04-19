import MySQLdb as mdb
import sys, bz2
import config

def ToObjectCode(objType):
	if objType == "node": return 0;
	if objType == "way": return 1;
	if objType == "relation": return 2;
	raise Exception("Unrecognised type")

class AssocTable:
	def __init__(self, cur):
		self.cur = cur
		self.childrenCache = {}

	def GetChildren(self, ty, oid, ver):
		if (ty, oid, ver) in self.childrenCache:
			return self.childrenCache[(ty, oid, ver)]

		sql = "SELECT ctype, cid FROM "+config.dbName+(".assoc WHERE pid={0}".format(oid))+\
			(" AND ptype={0}".format(ty))+\
			(" AND pver={0};".format(ver))
		self.cur.execute(sql)
		children = self.cur.fetchall()
		self.childrenCache[(ty, oid, ver)] = children
		return children

class MetaTable:
	def __init__(self, cur):
		self.cur = cur
		self.highestVerCache = {}

	def GetHighestVersionNumOfObj(self, ty, oid):
		if (ty, oid) in self.highestVerCache:
			return self.highestVerCache[(ty, oid)]

		sql = "SELECT MAX(pver) FROM "+config.dbName+(".meta WHERE id={0}".format(oid))+" AND type={0};".format(ty)
		cur.execute(sql)
		maxver = cur.fetchone()
		self.highestVerCache[(ty, oid)] = maxver[0]
		return maxver[0]

if __name__ == "__main__":

	con = mdb.connect(config.hostname, config.username, \
		config.password, config.dbName);
	cur = con.cursor()

	assocTable = AssocTable(cur)
	metaTable = MetaTable(cur)

	bbox = [-0.526129,51.267553,-0.5081755,51.2828722]
	nodesInBbox = {}

	#Get nodes in bbox
	sql = "SELECT AsText(geom.g), geom.id, geom.ver FROM "+config.dbName+".geom "\
		"WHERE Contains(GeomFromText('Polygon(({1} {0},{3} {0},{3} {2},{1} {0},{1} {0}))'),geom.g);".format(*bbox);
	print sql

	cur.execute(sql)
	nodeIds = cur.fetchall()

	print "Num bbox nodes", len(nodeIds)

	#Sort highest value version number and position for each node
	for nodeId in nodeIds:
		latLon = map(float, nodeId[0][6:-1].split(" "))

		if nodeId[1] not in nodesInBbox:
			nodesInBbox[nodeId[1]] = (nodeId[2], latLon)
		else:
			if nodeId[2] > nodesInBbox[nodeId[1]]:
				nodesInBbox[nodeId[1]] = (nodeId[2], latLon)

	print "Num distinct nodes", len(nodesInBbox)
	seekLi = []
	for nodeId in nodesInBbox:
		seekLi.append((0, nodeId))

	#Get parents
	while len(seekLi) > 0:
		obj = seekLi.pop(0)

		sql = "SELECT ptype, pid, pver FROM "+config.dbName+(".assoc WHERE cid={0}".format(obj[1]))+(" AND ctype={0};".format(obj[0]))
		#print sql

		cur.execute(sql)
		parents = cur.fetchall()
		if len(parents) > 0:
			print parents
			for p in parents:
				print p[0], p[1]
				#Get latest version
				maxver = metaTable.GetHighestVersionNumOfObj(p[0], p[1])
				
				#Get children of latest				
				children = assocTable.GetChildren(p[0], p[1], maxver)
				
				#Check if current object is a member
				
				




