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
		
		sql = "SELECT MAX(ver) FROM "+config.dbName+(".meta WHERE id={0}".format(oid))+" AND type={0};".format(ty)
		cur.execute(sql)
		maxver = cur.fetchone()
		self.highestVerCache[(ty, oid)] = maxver[0]
		return maxver[0]

class GeomTable:
	def __init__(self, cur):
		self.cur = cur
		self.nodeCache = {}

	def NodesInBbox(self, bbox):
		nodesInBbox = {}

		#Get nodes in bbox
		sql = "SELECT AsText(geom.g), geom.id, geom.ver FROM "+config.dbName+".geom "\
			"WHERE Contains(GeomFromText('Polygon(({1} {0},{3} {0},{3} {2},{1} {0},{1} {0}))'),geom.g);".format(*bbox);
		print sql

		self.cur.execute(sql)
		nodeIds = self.cur.fetchall()

		#Sort highest value version number and position for each node
		for nodeId in nodeIds:
			latLon = map(float, nodeId[0][6:-1].split(" "))
			self.nodeCache[(nodeId[1], nodeId[2])] = latLon

			if nodeId[1] not in nodesInBbox:
				nodesInBbox[nodeId[1]] = (nodeId[2], latLon)
			else:
				if nodeId[2] > nodesInBbox[nodeId[1]]:
					nodesInBbox[nodeId[1]] = (nodeId[2], latLon)

		return nodesInBbox

	def GetNode(self, nodeId, ver):

		if (nodeId, ver) in self.nodeCache:
			print "cache hit"
			return self.nodeCache[(nodeId, ver)]

		sql = "SELECT AsText(geom.g), geom.id, geom.ver FROM "+config.dbName+".geom "\
			"WHERE type=0 AND id={0} AND ver={1};".format(nodeId, ver);
		#print sql
		self.cur.execute(sql)
		recentNode = self.cur.fetchone()
		latLon = map(float, recentNode[0][6:-1].split(" "))
		self.nodeCache[(nodeId, ver)] = latLon
		return latLon

if __name__ == "__main__":

	con = mdb.connect(config.hostname, config.username, \
		config.password, config.dbName);
	cur = con.cursor()

	assocTable = AssocTable(cur)
	metaTable = MetaTable(cur)
	geomTable = GeomTable(cur)

	bbox = [-0.526129,51.267553,-0.5081755,51.2828722]
	nodesInBbox = geomTable.NodesInBbox(bbox)

	#Check if these are the highest known node versions
	for nodeId in nodesInBbox:
		maxver = metaTable.GetHighestVersionNumOfObj(0, nodeId)
		#print nodesInBbox[nodeId][0], maxver
		assert maxver >= nodesInBbox[nodeId][0]
		if maxver > nodesInBbox[nodeId][0]:
			#Update to more recent version
			latLon = geomTable.GetNode(nodeId, maxver)
			nodesInBbox[nodeId] = (maxver, latLon)

	#Nodes could be filtered to check they are in the bbox
	#TODO

	print "Num distinct nodes", len(nodesInBbox)
	seekLi = []
	for nodeId in nodesInBbox:
		seekLi.append((0, nodeId))

	coreObjs = []
	for nodeId in nodesInBbox:
		coreObjs.append((0, nodeId, nodesInBbox[nodeId][0]))

	#Get parents
	while len(seekLi) > 0:
		print "Seek list len", len(seekLi)
		obj = seekLi.pop(0)

		sql = "SELECT ptype, pid, pver FROM "+config.dbName+(".assoc WHERE cid={0}".format(obj[1]))+(" AND ctype={0};".format(obj[0]))
		#print sql

		cur.execute(sql)
		parents = cur.fetchall()
		if len(parents) > 0:
			for p in parents:
				#Get latest version
				maxver = metaTable.GetHighestVersionNumOfObj(p[0], p[1])
				
				#Get children of latest				
				children = assocTable.GetChildren(p[0], p[1], maxver)
				
				#Check if current object is a member
				hit = 0
				for child in children:
					if child[0] == p[0] and child[1] == p[1]:
						hit = 1				
				
				#Check if this is a newly discovered parent
				if (p[0], p[1], maxver) not in coreObjs:
					print "Confirmed parent", p[0], p[1]
					coreObjs.append((p[0], p[1], maxver))
					seekLi.append((p[0], p[1]))

	#Generate extended node list (including ways that extend out of the bbox)
	extendedObj = coreObjs[:]
	for coreObj in coreObjs:
		#Only process ways
		if coreObj[0] != 1:
			continue
		print coreObj

		children = assocTable.GetChildren(coreObj[0], coreObj[1], coreObj[2])
		for child in children:
			pass

