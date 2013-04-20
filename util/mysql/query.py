import MySQLdb as mdb
import sys, bz2, pickle
import config

def ToObjectCode(objType):
	if objType == "node": return 0
	if objType == "way": return 1
	if objType == "relation": return 2
	raise Exception("Unrecognised type")

def ObjectCodeToStr(objCode):
	if objCode == 0: return "node"
	if objCode == 1: return "way"
	if objCode == 2: return "relation"
	raise Exception("Unrecognised type")

class AssocTable:
	def __init__(self, cur):
		self.cur = cur
		self.childrenCache = {}

	def GetChildren(self, ty, oid, ver):
		if (ty, oid, ver) in self.childrenCache:
			return self.childrenCache[(ty, oid, ver)]

		sql = "SELECT ctype, cid, role FROM "+config.dbName+(".assoc WHERE pid={0}".format(oid))+\
			(" AND ptype={0}".format(ty))+\
			(" AND pver={0};".format(ver))
		self.cur.execute(sql)
		children = self.cur.fetchall()
		self.childrenCache[(ty, oid, ver)] = children
		return children

	def GetParents(self, ty, oid):
		sql = "SELECT ptype, pid, pver FROM "+config.dbName+(".assoc WHERE cid={0}".format(oid))+(" AND ctype={0};".format(ty))
		#print sql

		self.cur.execute(sql)
		parents = self.cur.fetchall()
		return parents

class MetaTable:
	def __init__(self, cur):
		self.cur = cur
		self.highestVerCache = {}
		self.metaCache = {}

	def GetHighestVersionNumOfObj(self, ty, oid):
		if (ty, oid) in self.highestVerCache:
			return self.highestVerCache[(ty, oid)]
		
		sql = "SELECT MAX(ver) FROM "+config.dbName+(".meta WHERE id={0}".format(oid))+" AND type={0};".format(ty)
		cur.execute(sql)
		maxver = cur.fetchone()
		self.highestVerCache[(ty, oid)] = maxver[0]
		return maxver[0]

	def GetObject(self, ty, oid, ver):
		if (ty, oid, ver) in self.metaCache:
			return self.metaCache[(ty, oid, ver)]
		sql = "SELECT changeset, user, uid, timestamp FROM "+\
			config.dbName+".meta WHERE id={1} AND type={0} AND ver={2};".format(ty, oid, ver)
		cur.execute(sql)
		obj = cur.fetchone()
		self.metaCache[(ty, oid, ver)] = obj
		return obj

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
			return self.nodeCache[(nodeId, ver)]

		sql = "SELECT AsText(geom.g), geom.id, geom.ver FROM "+config.dbName+".geom "\
			"WHERE id={0} AND type=0 AND ver={1};".format(nodeId, ver);
		#print sql
		self.cur.execute(sql)
		recentNode = self.cur.fetchone()
		latLon = map(float, recentNode[0][6:-1].split(" "))
		self.nodeCache[(nodeId, ver)] = latLon
		return latLon

class TagTable:
	def __init__(self, cur):
		self.cur = cur

	def GetObject(self, ty, oid, ver):
		sql = "SELECT k, v FROM "+config.dbName+".tags "\
			"WHERE id={1} AND type={0} AND ver={2};".format(ty, oid, ver);
		#print sql
		self.cur.execute(sql)
		tags = self.cur.fetchall()
		return tags

def QueryBbox(bbox, assocTable, metaTable, geomTable):
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
		if len(seekLi) % 1000==0:
			print "Seek list len", len(seekLi)
		obj = seekLi.pop(0)
		parents = assocTable.GetParents(obj[0], obj[1])

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

	#Generate extended node list (including nodes of ways that extend out of the bbox)
	extendedObjs = coreObjs[:]
	for count, coreObj in enumerate(coreObjs):
		#Only process ways
		if coreObj[0] != 1:
			continue
		print count, len(coreObjs), coreObj

		children = assocTable.GetChildren(coreObj[0], coreObj[1], coreObj[2])
		for child in children:
			maxver = metaTable.GetHighestVersionNumOfObj(child[0], child[1])
			if (child[0], child[1], maxver) not in extendedObjs:
				print "Found extended obj", (child[0], child[1], maxver)
				extendedObjs.append((child[0], child[1], maxver))

	return extendedObjs

def ObjToXml(ty, oid, ver, assocTable, metaTable, geomTable, tagTable):

	metaData = metaTable.GetObject(ty, oid, ver)
	if metaData == None:
		raise Exception("Object not found")

	out = unicode("")
	out += "  <{0} id='{1}' timestamp='{2}' uid='{3}' user='{4}' visible='{5}' version='{6}' changeset='{7}'"\
		.format(ObjectCodeToStr(ty), oid, metaData[3], metaData[2], metaData[1].decode('utf-8'), "true", ver, metaData[0])
	if ty == 0:
		node = geomTable.GetNode(oid, ver)
		out += " lat='{0}' lon='{1}'".format(*node)
	out += ">"

	tags = tagTable.GetObject(ty, oid, ver)
	for tag in tags:
		out += "    <tag k=\"{0}\" v=\"{1}\" />\n".format(tag[0].decode('utf-8'), tag[1].decode('utf-8'))

	if ty != 0: #Nodes can't have children
		children = assocTable.GetChildren(ty, oid, ver)
		for child in children:
			if ty == 1: out += "    <nd ref='"+str(child[1])+"' />\n"
			if ty == 2: out += "    <member type='{0}' ref='{1}' role='{2}' />\n".format(ObjectCodeToStr(child[0]), child[1], child[2])
	out += "</"+ObjectCodeToStr(ty)+">\n"
	return out.encode('utf-8')

def ObjListToXml(objs, bbox, assocTable, metaTable, geomTable, tagTable, out):

	out.write("<?xml version='1.0' encoding='UTF-8'?>\n")
	out.write("<osm version='0.6' generator='mysql-query'>\n")
	out.write("<bounds minlat='{0}' minlon='{1}' maxlat='{2}' maxlon='{3}' origin='mysql-query' />\n".format(bbox[1], bbox[0], bbox[3], bbox[2]))

	#Dump nodes
	for obj in objs:
		if obj[0] != 0: continue
		out.write(ObjToXml(obj[0], obj[1], obj[2], assocTable, metaTable, geomTable, tagTable))

	#Dump ways
	for obj in objs:
		if obj[0] != 1: continue
		out.write(ObjToXml(obj[0], obj[1], obj[2], assocTable, metaTable, geomTable, tagTable))

	#Dump relations
	for obj in objs:
		if obj[0] != 2: continue
		out.write(ObjToXml(obj[0], obj[1], obj[2], assocTable, metaTable, geomTable, tagTable))

	out.write("</osm>\n");

if __name__ == "__main__":

	con = mdb.connect(config.hostname, config.username, \
		config.password, config.dbName);
	cur = con.cursor()

	assocTable = AssocTable(cur)
	metaTable = MetaTable(cur)
	geomTable = GeomTable(cur)
	tagTable = TagTable(cur)

	bbox = [-0.526129,51.277553,-0.5081755,51.2828722] #Small area
	#bbox = [-0.6365204,51.2008603,-0.5081177,51.2750181] #Guildford area
	extendedObjs = QueryBbox(bbox, assocTable, metaTable, geomTable)

	pickle.dump(extendedObjs, open("extendedObj.dat","wb"), protocol = -1)

	#Dump extended objects to output
	fi = open("out.osm","wt")
	ObjListToXml(extendedObjs, bbox, assocTable, metaTable, geomTable, tagTable, fi)




