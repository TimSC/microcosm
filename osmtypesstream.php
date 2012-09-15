<?php

include_once ('osmtypes.php');

define("READ_FILE_PAGE_SIZE",8192);

function ExtractBz2($file,&$callback)
{
	$bz = bzopen($file, "r") or die("Couldn't open $file");

	while (!feof($bz)) {
	  $callback->Process(bzread($bz, READ_FILE_PAGE_SIZE));
	}
	bzclose($bz);
}

function ExtractOsmXml($file,&$callback)
{
	$handle = fopen($file, "rt");
	while (!feof($handle)) {
		$callback->Process(fread($handle, READ_FILE_PAGE_SIZE));
	}
	fclose($handle);
}

class ExtractToXml
{
	var $depth = 0;

	function startElement($parser, $name, $attrs) 
	{
		for ($i = 0; $i < $this->depth; $i++) 
		{
		echo "  ";
		}
		echo "$name\n";
		$this->depth++;
	}

	function endElement($parser, $name) 
	{
		$this->depth--;
	}

	function __construct()
	{
		$this->xml_parser = xml_parser_create();
		xml_set_element_handler($this->xml_parser, 
			array ( &$this, 'startElement' ), 
			array ( &$this, 'endElement' ));
	}

	function __destruct()
	{
		xml_parser_free($this->xml_parser);
	}

	function Process($data, $end=0)
	{
		//echo $data;
		if (!xml_parse($this->xml_parser, $data, $end))
		{
			die(sprintf("XML error: %s at line %d\n",
				xml_error_string(xml_get_error_code($xml_parser)),
				xml_get_current_line_number($xml_parser)));
		}
	}
}

$osmAttributesTypes = array('id'=>'int','action'=>'string',
	'lat'=>'float','lon'=>'float','changeset'=>'int',
	'user'=>'string','uid'=>'int','visible'=>'boolean',
	'timestamp'=>'timestamp','version'=>'int');

class OsmTypesStream extends ExtractToXml
{
	var $currentObj = null;
	public $callback = null;

	function startElement($parser, $name, $attrs) 
	{

		if($this->depth == 1)
		{
			//echo $name;
			//print_r($attrs);
			$this->currentObj = OsmElementFactory($name);

			if(is_object($this->currentObj))
			{
			//Copy attributes
			global $osmAttributesTypes;

			foreach($attrs as $k => $v)
			{
				if($osmAttributesTypes[strtolower($k)])
					$this->currentObj->attr[strtolower($k)] = ValidateValue($v,$osmAttributesTypes[strtolower($k)]);
				else
					$this->currentObj->attr[strtolower($k)] = ValidateValue($v,'string');
			}
			}
		}

		if($this->depth == 2 and is_object($this->currentObj))
		{
			//echo $name;
			//print_r($attrs);

			if(strcasecmp($name,"tag")==0)
			{
				//print_r($attrs);
				$this->currentObj->tags[ValidateValue($attrs['K'],'string')] = ValidateValue($attrs['V'],'string');
			}

			if(strcasecmp($name,"nd")==0)
			{
				//print_r($attrs);
				$ref = (int)$attrs['REF'];
				array_push($this->currentObj->nodes,array($ref,null));
			}

			if(strcasecmp($name,"member")==0)
			{
				//print_r($attrs);
				$ref = (int)$attrs['REF'];
				$type = ValidateValue($attrs['TYPE'],'string');
				$role = ValidateValue($attrs['ROLE'],'string');
				if(strcmp($type,"node")==0)
					array_push($this->currentObj->nodes,array($ref,$role));
				if(strcmp($type,"way")==0)
					array_push($this->currentObj->ways,array($ref,$role));
				if(strcmp($type,"relation")==0)
					array_push($this->currentObj->relations,array($ref,$role));
			}
		}

		$this->depth++;
	}

	function endElement($parser, $name) 
	{
		$this->depth--;

		if($this->depth == 1)
		{
			//Object is complete
			if(is_object($this->currentObj) and is_null($this->callback))
			print_r($this->currentObj->ToXmlString());

			//Send finished object to callback func
			if(!is_null($this->callback))
				call_user_func($this->callback, $this->currentObj);

			$this->currentObj = null;
		}
	}


}

?>
