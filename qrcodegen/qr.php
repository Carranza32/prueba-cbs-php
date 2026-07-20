  <?php
//include wp-config or wp-load.php
$root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));


if (file_exists($root . '/wp-load.php')) {
  // WP 2.6
  require_once($root . '/wp-load.php');
} else {
  // Before 2.6
  require_once($root . '/wp-config.php');
}

require_once("phpqrcode/qrlib.php");

function delete_product_img_from_folder()
{
  $files = glob('phpqrcode/temp/*'); // get all file names
foreach($files as $file){ // iterate files
  if(is_file($file)) {
   unlink($file); // delete file
  }
}

}

delete_product_img_from_folder();

// Path where the images will be saved
$siteurl=get_site_url();
$siteid=$_POST['site'];

$tablename=$_POST['areaExternalCode'];
$location = $_POST['location'];

$imgname=$siteid."_".$tablename.$location.".png";
$filepath = 'phpqrcode/temp/'.$imgname;
// Image (logo) to be drawn
// qr code content
$codeContents=$siteurl."/menu-items/?site_id=".$siteid."&location=".$location."&area=".$tablename;
//$codeContents = 'http://custombusinesssolutions.com/menus/?site_id=c18bab29-beda-4078-81d5-6791a2e17450&table_num=2';
// Create the file in the providen path
// Customize how you want
QRcode::png($codeContents,$filepath , QR_ECLEVEL_H, 4);

$newfilepath=plugins_url($filepath, __FILE__);

// Ouput image in the browser
echo '<img src="'.$newfilepath.'" />';
echo $codeContents;
