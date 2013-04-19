import MySQLdb as mdb
import sys, bz2
import config

def ToObjectCode(objType):
	if objType == "node": return 0;
	if objType == "way": return 1;
	if objType == "relation": return 2;
	raise Exception("Unrecognised type")


if __name__ == "__main__":



	con = mdb.connect(config.hostname, config.username, \
		config.password, config.dbName);
	cur = con.cursor()

	bbox = [-0.636129,51.207553,-0.5081755,51.2828722]
	objs = []

	#Get nodes in bbox
	sql = "SELECT AsText(geom.g), geom.id, geom.ver, meta.changeset FROM "+config.dbName+".geom "\
		"INNER JOIN "+config.dbName+".meta ON "+config.dbName+".geom.id = "+config.dbName+".meta.id "+\
		"AND "+config.dbName+".geom.type = "+config.dbName+".meta.type "+\
		"AND "+config.dbName+".geom.ver = "+config.dbName+".meta.ver "+\
		"WHERE Contains(GeomFromText('Polygon(({1} {0},{3} {0},{3} {2},{1} {0},{1} {0}))'),geom.g);".format(*bbox);
	print sql

	cur.execute(sql)
	nodeIds = cur.fetchall()
	for nodeId in nodeIds:
		print nodeId
		objs.append([0, nodeId[1], 0])

	#Get parents
	for obj in objs:
		pass
