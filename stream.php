<?php

/*

Description: mjpeg restreaming script with image overlay capability. 
Author: Stephen Price / Webmad
Version: 1.0.0
Author URI: http://www.webmad.co.nz
Usage: <img src="stream.php" />
Notes: If you are keen to have image overlays clickable, use html elements overlaying the <img> element (ie: wrap <img> in a <div> with position:relative; and add an <a> element with display:block;position:absolute;bottom:0px;left:0px;width:100%;height:15px;background:transparent;z-index:2;)

*/

// These settings would read an mjpeg stream from http://192.168.1.1:80/videostream.cgi?user=admin&password=pass
$host = "192.168.1.1";
$port = "80";
$url = "/videostream.cgi?user=admin&password=pass";

// Image settings:
$overlay = "bannerad.png";	//image that will be superimposed onto the stream
$fallback = "webcam.jpg";	//image that will get updated every 20 frames or so for browsers that don't support mjpeg streams
$boundary = "IPCamera_Logo";	

///////////////////////////////////////////////////////////////////////////////////////////////////
// Stuff below here will break things if edited. Avert your eyes unless you know what you are doing
// (or can make it look like you know what you are doing, and won't get naggy if you can't fix it.)
///////////////////////////////////////////////////////////////////////////////////////////////////

set_time_limit(45);

$start = time();
$in2 = imageCreateFromPNG($overlay);

$tmid = shmop_open(0xff4, 'c', 0777, 1024);
$tdmid = shmop_open(0xff6, 'c', 0777, 102400);

$data = unserialize(trim(shmop_read($tmid, 0, 1024)));
if(!isset($data['updated'])||$data['updated']<(time()-5)){
	fresh();
}

header('Accept-Range: bytes');
header('Connection: close');
header('Content-Type: multipart/x-mixed-replace;boundary='.$boundary);
header('Cache-Control: no-cache');

$curframe = $data['frame'];

//var_dump($frame);
while($data['updated']>(time()-5)){
	if($curframe!=$data['frame']){
		$curframe = $data['frame'];
		
		$frames = unserialize(trim(shmop_read($tdmid, 0, 102400)));
		$key=array_pop(array_keys($frames));
		echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: ".strlen($frames[$key])."\r\n\r\n".$frames[$key];
		flush();
	}
	usleep(50000);
	$data = unserialize(trim(shmop_read($tmid, 0, 1024)));
}
if((time()-$start)<30){
	fresh();
}

shmop_close($tdmid);
shmop_close($tmid);

exit;





function output($in){
	global $in2;
	$string = date('r')."";
	imagecopy($in,$in2,0,0,0,0,640,480);
	$font = 1;
	$width = imagefontwidth($font) * strlen($string) ;
	$height = imagefontheight($font)+30 ;
	$x = imagesx($in) - $width ;
	$y = imagesy($in) - $height;
	$backgroundColor = imagecolorallocate ($in, 255, 255, 255);
	$textColor = imagecolorallocate ($in, 0, 0,0);
	imagestring ($in, $font, $x, $y,  $string, $textColor);
	imagejpeg($in,NULL,60);
}

function fresh(){
	global $data,$tmid,$tdmid,$start,$in2,$host,$port,$url,$boundary,$fallback;
	
	if(!headers_sent()){
		header('Accept-Range: bytes');
		header('Connection: close');
		header('Content-Type: multipart/x-mixed-replace;boundary='.$boundary);
		header('Cache-Control: no-cache');
	}
	
	$data['updated']=time();
	$data['frame']=0;
	$frames = array();
	shmop_write($tmid, str_pad(serialize($data),1024,' '), 0);
	
	$fp = @fsockopen($host, $port, $errno, $errstr, 10);
	if($fp){
		$out = "GET $url HTTP/1.1\r\n";
	    $out .= "Host: $host\r\n";
	    $out .= "\r\n";
	    fwrite($fp, $out);
	    $ec = "";
	    $in = false;
	    $buffer='';
	    while (!feof($fp)) {	    	
	        $part= fgets($fp);
	        if(strstr($part,'--'.$boundary)){
	        	$in=true;
	        }
	        $buffer .= $part;
	    	$part=$buffer;
	    	if(substr(trim($part),0,2)=="--")$part = substr($part,3);
			$part = substr($part,strpos($part,'--'.$boundary)+strlen('--'.$boundary));
			$part = trim(substr($part,strpos($part,"\r\n\r\n")));
			$part = substr($part,0,strpos($part,'--'.$boundary));
			
			$img = @imagecreatefromstring($part);
			if($img){	
				$buffer = substr($buffer,strpos($buffer,$part)+strlen($part));				
				ob_start();
				output($img,true);	//,null,60
				$imgstr = ob_get_contents();
				ob_end_clean();	
				$data['frame']++;
				$data['updated']=time();
				while(count($frames)>2)array_shift($frames);
				$frames[] = $imgstr;
				shmop_write($tdmid, str_pad(serialize($frames),102400,' '), 0);
				shmop_write($tmid, str_pad(serialize($data),1024,' '), 0);
								
				echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: ".strlen($imgstr)."\r\n\r\n".$imgstr;	//$frames[$data['frame']]
				if(($data['frame']/20)-(ceil($data['frame']/20))==0)file_put_contents($fallback,$imgstr);
				if((time()-$start)>45){
					exit;
				}
				flush();
			}
	    }
	}
	else{			
		$img = imageCreateFromJPEG($fallback);
		
		imagestring($in,3,25,180,"Could not connect to the camera source",imagecolorallocate($in,255,255,255));
		imagestring($in,3,65,195,"Please try again later...",imagecolorallocate($in,255,255,255));
		
		ob_start();
		output($img);
		$imgstr = ob_get_contents();
		ob_end_clean();	
		echo "--$boundary\r\nContent-Type: image/jpeg\r\nContent-Length: ".strlen($imgstr)."\r\n\r\n".$imgstr;
		flush();
	}
}