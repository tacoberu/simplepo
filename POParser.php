<?php


function debug($text)
{
    echo "<pre>";
    print_r($text);
    echo "</pre>";
}


interface PoMsgStore
{
	function write($msg, $isHeader);
	function read();
	function clean( $msgid_keys );
}



class TempPoMsgStore implements PoMsgStore
{
	private $msgs;
	private $deleted;


	function write($msg,$isHeader)
	{
		 $this->msgs[] = $msg;
	}



	function read()
	{
		return $this->msgs;
	}



	function clean($msgid_keys)
	{
		return $this->deleted;
	}

}

class DBPoMsgStore implements PoMsgStore
{
	private $filled_keys = array();

	function init($catalogue_name)
	{
		$q = new Query();
		$catalogue = $q->sql("SELECT * FROM {catalogues} WHERE name = ?", $catalogue_name)->fetch();
		if (!$catalogue) {
			$q->sql("INSERT INTO {catalogues} (name) VALUES (?)", $catalogue_name)->execute();
			$this->catalogue_id = $q->insertId();
		}
		else {
			$this->catalogue_id = $catalogue['id'];
		}
		// Get keys to prevent them to be erased
		$keys = $q->sql("SELECT msgid FROM {messages} WHERE catalogue_id=? AND msgstr != ''", $this->catalogue_id)->fetchCol();

		// Hashmap for perfs
		if ( is_array($keys) ) {
			foreach ($keys as $key) {
				$this->filled_keys[$key] = $key;
			}
		}
	}



	function write($msg, $isHeader)
	{
		$q = new Query();
		$msg['is_obsolete'] = !!$msg['is_obsolete'] ? 1 : 0;
		$msg['is_header'] = $isHeader ? 1 : 0;

		// TODO put this optional. Keys can be erased if option is on.
		// Give priority to simplepo for already translated keys.
		if ( !isset( $this->filled_keys[$msg["msgid"]])) {
			$q->sql("DELETE FROM {messages}
					 WHERE  catalogue_id=? AND BINARY msgid= BINARY ? AND msgstr = ''",
					 $this->catalogue_id,$msg["msgid"])
				 ->execute();
			// FIXME Need to be checked: was the previous delete done? if yes go on else stop.
			$allow_empty_fields = array('translator-comments', 'extracted-comments', 'reference', 'flags', 'is_obsolete', 'previous-untranslated-string');
			foreach ($allow_empty_fields as $field) {
				if (!isset($msg[$field])) {
					$msg[$field] = '';
				}
			}
			$q->sql("INSERT INTO {messages}
					 (catalogue_id, msgid, msgstr, comments, extracted_comments,
					 reference,flags, is_obsolete, previous_untranslated_string,
					 is_header) VALUES (?, ?, ?, ?, ?, ?, ?, ?,?,?)",
					 $this->catalogue_id , $msg["msgid"], $msg["msgstr"],
					 $msg["translator-comments"], $msg["extracted-comments"],
					 $msg["reference"], $msg["flags"], $msg["is_obsolete"],
					 $msg["previous-untranslated-string"],$msg['is_header'])
				 ->execute();
		}
	}



	function read()
	{
		$q = new Query();
		return $q->sql("SELECT * FROM {messages} WHERE catalogue_id = ? ORDER BY is_header DESC,is_obsolete,id", $this->catalogue_id)->fetchAll();
	}



	function clean($keys)
	{
		$todel = "";
		$unused = "";
		$used = "";
		$i = 0;

		// Get database keys
		$q = new Query();
		$msgs = $q->sql("SELECT msgid,msgstr FROM {messages} WHERE catalogue_id = ?", $this->catalogue_id)->fetchAll();

		// Compare database and file keys
		if ( is_array($msgs) ) {
			foreach ( $msgs as $msg ) {
				if ( $msg["msgid"] == 'login_account' ) {
					//var_dump($msg["msgid"]);
					//var_dump($keys);
				}
				// TODO handle special char on msgid (like " or ,)
				if ( !isset( $keys['"'.$msg["msgid"].'"'])) {
					if ( empty( $msg["msgstr"] ) ) {
						// delete unused key
						$todel .= ($todel=="") ? '"'.$msg["msgid"].'"' : ',"'.$msg["msgid"].'"';
					} else {
						// mark obsolete unused key
						$unused .= ($unused=="") ? '"'.$msg["msgid"].'"' : ',"'.$msg["msgid"].'"';
					}
					$i++;
				}
				else {
					$used .= ($used=="") ? '"'.$msg["msgid"].'"' : ',"'.$msg["msgid"].'"';
				}
			}
		}
		if ( !empty($todel) ) {
			$q->sql("DELETE FROM {messages}
					 WHERE  catalogue_id=? AND msgid IN ($todel) AND msgstr = ''",
					$this->catalogue_id)
				->execute();
		}
		if ( !empty($unused) ) {
			$q->sql("UPDATE {messages}
					 SET is_obsolete = 1 WHERE  catalogue_id=? AND msgid IN ($unused)",
					$this->catalogue_id,$unused)
				->execute();
		}
		if ( !empty($used) ) {
			$q->sql("UPDATE {messages}
					 SET is_obsolete = 0 WHERE  catalogue_id=? AND msgid IN ($used)",
					$this->catalogue_id,$used)
				->execute();
		}

		return  $i;
	}

}



class POParser
{

	public $fileHandle;
	protected $context = array();
	public $entryStore;
	private $lineNumber;

	protected $match_expressions = array(
		array(
			'type' => 'translator-comments',
			're_match' => '/(^# )|(^#$)/',
			're_capture' => '/#\s*(.*)$/',
		),
		array(
			'type' => 'extracted-comments',
			're_match' => '/^#. /',
			're_capture' => '/#.\s+(.*)$/',
		),
		array(
			'type' => 'reference',
			're_match' => '/^#: /',
			're_capture' => '/#:\s+(.*)$/',

		),
		array(
			'type' => 'flags',
			're_match' => '/^#, /',
			're_capture' => '/#,\s+(.*)$/',
		),
		array(
			'type' => 'previous-untranslated-string',
			're_match' => '/^#\| /',
			're_capture' => '/#\|\s+(.*)$/',
		),
		array(
			'type' => 'msgid',
			're_match' => '/^msgid /',
			're_capture' => '/msgid\s+(".*")/',
		),
		array(
			'type' => 'msgstr',
			're_match' => '/^msgstr /',
			're_capture' => '/msgstr\s+(".*")/',
		),
		array(
			'type' => 'string',
			're_match' => '/^\s*"/',
			're_capture' => '/^\s*(".*")/',
		),
		array(
			'type' => 'obsolete-msgid',
			're_match' => '/^#~\s+msgid /',
			're_capture' => '/#~\s+msgid\s+(".*")/',
		),
		array(
			'type' => 'obsolete-msgstr',
			're_match' => '/^#~\s+msgstr /',
			're_capture' => '/#~\s+msgstr\s+(".*")/',
		),
		array(
			'type' => 'obsolete-string',
			're_match' => '/^#~\s+\s*"/',
			're_capture' => '/^#~\s+(".*")/',
		),
		array(
			'type' => 'empty',
			're_match' => '/^$/',
			're_capture' => '/^()$/'
		)

	);



	function __construct($entryStore)
	{
		$this->entryStore = $entryStore;
	}



	function writePoFileToStream($fh)
	{
		$entries = $this->entryStore->read();
		foreach ($entries as $entry) {
			fwrite( $fh, $this->convertEntryToString($entry));
		}
	}



	function parseEntriesFromStream($fh)
	{
		$this->lineNumber = 0;
		$entry_count = 0;
		$entry_lines = array();

		while( ($line = fgets($fh)) !== false ) {
			$this->lineNumber++;
			$line = $this->parseLine($line);

			if ( $line["type"] != "empty" ){
				$entry_lines[] = $line;
			}
			else {
				$entry = $this->reduceLines($entry_lines);
				$this->saveEntry( $entry, $entry_count++ );
				$entry_lines = array();
			}
		}

		if ( $entry_lines ) {
			$entry = $this->reduceLines($entry_lines);
			$this->saveEntry( $entry, $entry_count++ );
		}
	}



	function cleanDatabase($fh)
	{
		$this->lineNumber = 0;
		$entry_count = 0;
		$deleted = 0;
		$msgid_keys = array();

		// First, build a map with all the file keyid
		while( ($line = fgets($fh)) !== false ) {
			$this->lineNumber++;
			$line = $this->parseLine($line);
			if ( $line["type"] == "msgid" ) {
				$msgid_keys[$line["value"]] = $line["value"];
				if ($msgid_keys[$line["value"]] == "login_account") {
					var_dump("$msgid_keys");
				}
			}
		}

		// Now test each database keyid and delete or mark it obsolete.
		$deleted = $this->entryStore->clean($msgid_keys);
		return $deleted;
	}



	function parseLine($line)
	{
		$this->lineNumber++;
		$line_object = array();
		foreach ($this->match_expressions as $m) {
			if (preg_match($m['re_match'],$line) ) {
				preg_match($m['re_capture'],$line,$matches);
				$line_object['value'] = $matches[1];
				$line_object['type'] = $m['type'];
			}
		}

		// didn't match anything
		if (!$line_object) {
			throw new Exception( sprintf("unrecognized line fomat at line: %d",$this->lineNumber) );
		}

		return $line_object;
	}



	function decodeStringFormat($str)
	{
		if ( substr($str, 0, 1) == '"' && substr($str, -1,1) == '"' ) {
			$result = substr($str, 1, -1);
			$translations = array("\\\\"=>"\\", "\\n"=>"\n",'\\"'=>'"');
			$result = strtr($result, $translations);
		}
		else {
			throw new Exception("Invalid PO string (should be surrounded by quotes)\n$str\n");
		}
		return $result;
	}



	/**
	 *  translates
	 *  Hello"
	 *  World
	 *  to
	 *	 ""
	 *  "Hello\"\n"
	 *	 "World"
	 */
	function encodeStringFormat($message_string)
	{
		// translate the characters to escapted versions.
		$translations = array("\n"=>"\\n",'"'=>'\\"',"\\"=>"\\\\");
		$result = strtr($message_string, $translations);

		// put the \n's at the end of the lines.
		$result = str_replace("\\n","\\n\n",$result);

		// wrap text so po files can be edited nicely.
		$lines = explode("\n",$result);
		foreach ($lines as &$l) {
			$l = $this->wordwrap($l,78);
		}
		$result = implode("\n",$lines);

		// if there are mutiple lines, lets prefix everything with a ""  like the gettext tools
		if (strpos($result,"\n")) {
			$result = "\n" . $result;
		}

		// wrap each line in quotes
		$result = $this->addPrefixToLines('"',$result);
		$result = $this->addSuffixToLines('"',$result);

		return $result;
	}



	function addPrefixToLines($prefix, $text)
	{
		$text = explode("\n",$text);
		foreach ($text as &$line) {
			$line = $prefix . $line;
		}
		return implode("\n",$text);
	}



	function addSuffixToLines($suffix, $text)
	{
		$text = explode("\n",$text);
		foreach ($text as &$line) {
			$line = $line . $suffix;
		}
		return implode("\n",$text);
	}



	function wordwrap($text,$max_len=75)
	{
		$result = "";
		$ll=0;
		$words = explode(" ",$text);
		foreach ($words as $w) {
			$lw = mb_strlen($w,'UTF-8');
			if ( $ll + $lw + 1 < $max_len) {
				$result .= $w ." ";
				$ll += $lw + 1;
			}
			else {
				$result .= "\n" . $w . " ";
				$ll = $lw + 1;
			}
		}
		$result = substr($result,0,-1);
		return $result;
	}



	function saveEntry($entry, $entry_count)
	{
		$this->entryStore->write($entry, $entry_count == 0 );
	}



	function reduceLines($entry_lines)
	{
		$entry = array();
		$context = "";
		$is_obsolete = false;

		foreach ($entry_lines as $line) {
			// convert the obsolete types into normal type, and mark as obsolete;
			if (substr($line['type'],0,9) == "obsolete-") {
				$is_obsolete = true;
				$line['type'] = substr($line['type'],9);
				preg_match('/".*"/',$line['value'],$m);
				$line['value'] = $m[0];
			}

			if ($line['type'] == "string") {
				if ($context == "msgid" || $context == "msgstr") {
				  $entry[ $context ][] = $this->decodeStringFormat( $line['value'] );
				}
				else{
				  throw new Exception("String in invalid position: " . $line["value"]);
				}
			}
			else {
				$context = $line["type"];
				if ( $line["type"] == "msgid" || $line["type"] == "msgstr" ) {
					$entry[$line["type"]][] = $this->decodeStringFormat( $line["value"] );
				}
				else {
					$entry[$line["type"]][] = $line["value"];
				}
			}
		}

		foreach ($entry as $k=>&$v) {
			if ( in_array($k,array('msgid',"msgstr")) ){
				$v  = implode('',$v);
			}
			else{
				$v = implode("\n",$v);
			}
		}
		$entry['is_obsolete'] = $is_obsolete;

		return $entry;
	}



	function convertEntryToString($entry)
	{
		$prefixes = array(
			"comments"=>"# ",
			"extracted_comments"=>"#. ",
			"reference"=>"#: ",
			"flags"=>"#, ",
			"previous_untranslated_string"=>"#| "
		);

		$msg = "";
		foreach ( $entry as $k=>$v ){
			if ($v && isset($prefixes[$k])) {
				$msg .= $this->addPrefixToLines($prefixes[$k],$v) . "\n";
			}
		}
		$msgid = 'msgid ' . $this->encodeStringFormat($entry['msgid']);
		$msgstr = 'msgstr ' . $this->encodeStringFormat($entry['msgstr']);

		if ($entry['is_obsolete']) {
			$msgid =  $this->addPrefixToLines('#~ ',$msgid);
			$msgstr = $this->addPrefixToLines('#~ ',$msgstr);
		}

		$msg .= $msgid . "\n";
		$msg .= $msgstr . "\n";
		$msg .= "\n";

		return $msg;
	}


}
