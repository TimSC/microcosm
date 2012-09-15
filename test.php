<?php
include_once('modelfactory.php');
exit();
$db = OsmDatabase();


$v1 = SingleObjectFromXml('<osm><node id="432375170" lat="51.2368223" lon="-0.5732176" changeset="1706961" user="TimSC" uid="6809" visible="true" timestamp="2009-07-02T06:36:13Z" version="1">
    <tag k="denomination" v="quakers"/>
    <tag k="amenity" v="place_of_worship"/>
    <tag k="religion" v="christian"/>
  </node></osm>');

//print_r($v1);

$db->CreateElement("node",432375170,$v1);

$v2 = SingleObjectFromXml('<osm>  <node id="432375170" lat="51.2368223" lon="-0.5732176" changeset="1706980" user="TimSC" uid="6809" visible="true" timestamp="2009-07-02T06:40:08Z" version="2">
    <tag k="denomination" v="quakers"/>
    <tag k="amenity" v="place_of_worship"/>

    <tag k="note" v="FIXME Confirm location"/>
    <tag k="religion" v="christian"/>
  </node></osm>');

$db->ModifyElement("node",432375170,$v2);

$v3 = SingleObjectFromXml('<osm><node id="432375170" lat="51.2365764" lon="-0.5743169" changeset="4909587" user="TimSC" uid="6809" visible="false" timestamp="2010-06-05T15:14:12Z" version="3">
    <tag k="denomination" v="quaker"/>
    <tag k="amenity" v="place_of_worship"/>

    <tag k="note" v="FIXME Confirm location"/>
    <tag k="religion" v="christian"/>
  </node></osm>');

$db->DeleteElement("node",432375170,$v3);

?>
