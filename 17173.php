<?php
require_once 'simplehtmldom/simple_html_dom.php';
require_once 'Snoopy/Snoopy.class.php';
$base = "http://cha.17173.com";
$debug = 1;
$success = 0;

$longopts = array("item:", "recipe:", "json:");
$options = getopt("c", $longopts);

$continue = (isset($options['c']))?1:0;
class FFXIVDB extends SQLite3
{
	function __construct()
	{
		$this->open('ffxiv.sqlite');
	}
}
$db = new FFXIVDB();
if (isset($options['item'])) {
	$itemurl = $base."/ff14/item";
	if ($continue)
	{
		handle_item($itemurl, $options['item']);
	}
	else
	{
		scan_all_items($itemurl."?page=".$options['item']);
	}
} else if (isset($options['recipe'])) {
	$recipeurl = $base."/ff14/recipe";
	if ($continue) {
		handle_recipe($recipeurl, $options['recipe']);
	}
	else
	{
		scan_all_recipes($recipeurl."?page=".$options['recipe']);
	}
} else if (isset($options['json'])) {
	handle_json($options['json']);
}
 
function handle_json($dir)
{
	$handler = opendir($dir);
	$ldb = $GLOBALS['db'];
	while (($filename = readdir($handler))!== false) {
		if ($filename != '.' and $filename != '..')
		{
			$contents = file_get_contents($dir.'/'.$filename);
			$idlist = json_decode($contents);
			foreach($idlist as $id)
			{
	//			echo "id is ".$id->ID." and name is ".$id->Name."\n";
				
				$ldb->exec( " update basicId set ID = $id->ID where name='$id->Name' ");
			}
		}
		
	}
}
function handle_item($url, $page) {
	for ($i = 1; $i <= $page; $i++)
	{
		if ($GLOBALS['debug'] == 1)
		{
			echo "continue mode: process item page $i\n";
		}
	
		scan_all_items($url."?page=".$i);
	}
}
function handle_recipe($url, $page) {
	for ($i = 1; $i <= $page; $i++)
	{
		scan_all_recipes($url."?page=".$i);
	}
}
function scan_all_items($url)
{
	$dom = file_get_html($url);
	$table = $dom->find('table', 0);
	$ldb = $GLOBALS['db'];
	if ($GLOBALS['debug'] == 1)
	{
		echo "url is $url\n";
	}
	if($table)
	{
		$alltr = $table->find('.fb');
		for ($i = 0; $i < count($alltr); $i++)
		{
			$a = $alltr[$i];
			$link = $a->href;
			$itemlink = $GLOBALS['base'].$link;
			$name = $a->plaintext;
			$id = substr($link, 11);
			$ldb->exec( " INSERT INTO basicId (name, ID_17173) values ('$name', '$id') ");
		}
	}
}
function scan_all_recipes($url)
{
	$dom = file_get_html($url);
	$ldb = $GLOBALS['db'];
	$table = $dom->find('table', 0);
	if ($GLOBALS['debug'] == 1)
	{
		echo "url is $url\n";
	}
	if($table)
	{
		$alltr = $table->find('tr');
		for ($i = 1; $i < count($alltr); $i++)
		{
			
			$aofrecipe = $alltr[$i]->find('td',1)->find('a', 0);
			$link = $aofrecipe->href;
			$itemlink = $GLOBALS['base'].$link;
			$rid = substr($link, 11);
			$items = $alltr[$i]->find('td', 5)->find('p');
			foreach ($items as $item)
			{
				$itemid = substr($item->find('a', 0)->href, 11);
				$pattern = "/x\d+/";
				preg_match($pattern, $item->plaintext, $matches);
				if (count($matches) > 0)
				{
					$count = substr($matches[0],1);
					echo "recipe $rid \t item id $itemid \t count $matches[0]\n";
					$ldb->exec( " INSERT INTO recipes (ID_recipe, ID_item, count) values ('$rid', '$itemid', $count) ");
				}
			}
		}
	}

}
function handle_artist($url)
{
	$dom = file_get_html($url);
	$details = $dom->find('.detail');
	foreach ($details as $detail)
	{
		$link = $detail->find('p', 0)->find('a', 0)->href;
		$album = $GLOBALS['base'].$link;
		unset($link);
		handle_album($album);
		unset($album);
		var_dump(memory_get_usage());
	}
	if ($GLOBALS['continue']) {
		$next = $dom->find('.p_redirect_l', 0);
		if ($next) {
			$next_page_link = $GLOBALS['base'].$next->href;
			handle_artist($next_page_link);
		}
		unset($next);
	}
	unset($details);
	unset($dom);
}

function handle_album($album_url)
{
	$dom = file_get_html($album_url);
	if (!$dom) return;
	$songs = $dom->find('.song_name');
	foreach ($songs as $song)
	{
		$a = $song->find('a', 0);
		if (isset($a->href))
		{
			$songAddress = $GLOBALS['base'].$a->href;
			$songid = substr($a->href, 6);
			handle_song($songAddress, $songid);
		}
	}
	$album = $dom->find('#title', 0)->find('h1', 0)->plaintext;
	$artist = $dom->find('#album_info', 0)->find('tr',0)->find('a', 0)->plaintext;


	$newt = "专辑名称: ".$artist." - ".$album."\n";
	$newt .= "专辑链接: ".$album_url;
	echo "\n".$newt."\n";
	unset($newt);
	unset($songs);
	unset($dom);
}

function handle_song($song, $id)
{
	$dom = file_get_html($song);
	if (!$dom) return;
	$lrc = $dom->find('#lyric', 0);
	$lrc_main = $dom->find('.lrc_main');
	if ($GLOBALS['artist']) {
		$artist = $GLOBALS['artist'];
	} else 
		$artist = htmlspecialchars_decode($dom->find('#albums_info', 0)->find('a', 1)->plaintext, ENT_QUOTES);
	$nameold = htmlspecialchars_decode($dom->find('#title',0)->find('h1',0)->innertext, ENT_QUOTES);
	$pattern = '/<span>.*<\/span>/';
	$name = preg_replace($pattern, '', $nameold);
	
	if (!$GLOBALS['force'] && count($lrc->children) && count($lrc_main))
	{
		echo $artist." - ".$name." already has lyric.\n";
		return;
	}
	$append = false;
	$origin = "";
	if ($GLOBALS['force'] && count($lrc->children) && count($lrc_main))
	{
		$editor_name = $lrc->find('a',0)->plaintext;
		if ($editor_name == "color") {
			echo "You have post $artist - $name's lyric\n";
			return;
		} 
		else {
			$origin = htmlspecialchars_decode($lrc_main[0]->plaintext, ENT_QUOTES);
			echo $origin;
			echo "\nreplace the lyrics? <y/n/a> ";
			$char = fgetc(STDIN);
			$ent = fgetc(STDIN);
			if ($char == 'n') {
				return;	
			}
			if ($char == 'a')
			{
				$append = true;
			}
		}
	}
	$lyric = find_lyrics($artist, $name);
	if ($lyric == null)
		return;
	if ($append)
	{
		$lyric['lyric_text'] .= "\n----------------------------------------\n\n\n".$origin;
	}
	$lyric['id']=$id;
	$lyric['_xiamitoken'] = 'b802235bc802f2f936798ab27fe108cb';
	var_dump($lyric);
	$editLink = "http://www.xiami.com/wiki/addlyric";
	post_lyric($editLink, $lyric);	
	$GLOBALS['success']++;
	echo $artist." - ".$name." successfully post.\n";
	unset($dom);
}

function find_lyrics($artist, $name)
{
	$url = "http://j-lyric.net/index.php?kt=".urlencode($name)."&ct=0&ka=".urlencode($artist)."&ca=0&kl=&cl=0";
	$dom = file_get_html($url);
	if (!$dom) return null;
	$body = $dom->find('.body', 0);
	if (!count($body->find('a'))) {
		echo "no lyric for $name\n";
		if (preg_match("/[~\(-]/", $name)) {

			$name = preg_replace("/[~\(-].*$/", "", $name);
			echo "continue find $name\n";
			return find_lyrics($artist, $name);
		}
		return null;
	}
	$link = $body->find('.title', 0)->find('a', 0)->href;
	$lrc_link = "http://j-lyric.net".$link;

	@$dom = file_get_html($lrc_link);
	if ($dom) {
		$body = $dom->find('.body', 0);
		if ($body) {
			$lyric['songwriters'] =  htmlspecialchars_decode(substr($body->children(0)->children(0)->children(1)->plaintext, 9), ENT_QUOTES);
			$lyric['composer'] =  htmlspecialchars_decode(substr($body->children(0)->children(0)->children(2)->plaintext, 9), ENT_QUOTES);
			$lrc = $dom->find('#lyricBody', 0);

			$lyric['lyric_text'] = $lrc->plaintext;
			$lyric['lyric_text'] = htmlspecialchars_decode($lyric['lyric_text'], ENT_QUOTES);
			$lyric['lyric_text'] = trim(preg_replace("/^  /m","",$lyric['lyric_text']));
			$lyric["submit"] = "保存";
			unset($dom);
			return $lyric;
		}
	}
	unset($dom);
	return null;
}
function login($snoopy)
{
	$submit_url = "http://www.xiami.com/member/login";
	$submit_vars["autologin"] = 1;
	$submit_vars["done"] = "/";
	$submit_vars["email"] = "violet_1986@163.com";
	$submit_vars["password"] = "187455v";
	$submit_vars["type"] = "";
	$submit_vars["submit"] = "登 录";
	$snoopy->submit($submit_url,$submit_vars);
}

function post_lyric($url, $lrc) {
	$GLOBALS['snoopy']->fetchform($url);

	$GLOBALS['snoopy']->submit($url, $lrc);
}
?>
