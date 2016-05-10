<?php

ini_set("memory_limit","512M");
ini_set('max_execution_time', 0); 

static $registry = NULL;
// we use the PHPExcel package from http://phpexcel.codeplex.com/
// require_once( '/Library/WebServer/Documents/store/system/PHPExcel/Classes/PHPExcel.php' );
// Error Handler
function error_handler_for_rlmexport($errno, $errstr, $errfile, $errline) {
	global $registry;
	
	switch ($errno) {
		case E_NOTICE:
		case E_USER_NOTICE:
			$errors = "Notice";
			break;
		case E_WARNING:
		case E_USER_WARNING:
			$errors = "Warning";
			break;
		case E_ERROR:
		case E_USER_ERROR:
			$errors = "Fatal Error";
			break;
		default:
			$errors = "Unknown";
			break;
	}
	
	$config = $registry->get('config');
	$url = $registry->get('url');
	$request = $registry->get('request');
	$session = $registry->get('session');
	$log = $registry->get('log');
	
	if ($config->get('config_error_log')) {
		$log->write('PHP ' . $errors . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
	}

	if (($errors=='Warning') || ($errors=='Unknown')) {
		return true;
	}

	if (($errors != "Fatal Error") && isset($request->get['route']) && ($request->get['route']!='awang/rlmexport/download'))  {
		if ($config->get('config_error_display')) {
			echo '<b>' . $errors . '</b>: ' . $errstr . ' in <b>' . $errfile . '</b> on line <b>' . $errline . '</b>';
		}
	} else {
		$session->data['export_error'] = array( 'errstr'=>$errstr, 'errno'=>$errno, 'errfile'=>$errfile, 'errline'=>$errline );
		$token = $request->get['token'];
		$link = $url->link( 'awang/rlmexport', 'token='.$token, 'SSL' );
		header('Status: ' . 302);
		header('Location: ' . str_replace(array('&amp;', "\n", "\r"), array('&', '', ''), $link));
		exit();
	}

	return true;
}


function fatal_error_shutdown_handler_for_rlmexport()
{
	$last_error = error_get_last();
	if ($last_error['type'] === E_ERROR) {
		// fatal error
		error_handler_for_export(E_ERROR, $last_error['message'], $last_error['file'], $last_error['line']);
	}
}


class ModelAwangRlmexport extends Model {

	private $error = array();

	function clean( &$str, $allowBlanks=FALSE ) {
		$result = "";
		$n = strlen( $str );
		for ($m=0; $m<$n; $m++) {
			$ch = substr( $str, $m, 1 );
			if (($ch==" ") && (!$allowBlanks) || ($ch=="\n") || ($ch=="\r") || ($ch=="\t") || ($ch=="\0") || ($ch=="\x0B")) {
				continue;
			}
			$result .= $ch;
		}
		return $result;
	}


	function multiquery( &$database, $sql ) {
		foreach (explode(";\n", $sql) as $sql) {
			$sql = trim($sql);
			if ($sql) {
				$database->query($sql);
			}
		}
	}


	protected function getDefaultLanguageId( &$database ) {
		$code = $this->config->get('config_language');
		$sql = "SELECT language_id FROM ".DB_PREFIX."language WHERE code = '$code'";
		$result = $database->query( $sql );
		$languageId = 1;
		if ($result->rows) {
			foreach ($result->rows as $row) {
				$languageId = $row['language_id'];
				break;
			}
		}
		return $languageId;
	}


	protected function getDefaultWeightUnit() {
		$weightUnit = $this->config->get( 'config_weight_class' );
		return $weightUnit;
	}


	protected function getDefaultMeasurementUnit() {
		$measurementUnit = $this->config->get( 'config_length_class' );
		return $measurementUnit;
	}



	function storeManufacturersIntoDatabase( &$database, &$products, &$manufacturerIds ) {
		// find all manufacturers already stored in the database
		$sql = "SELECT manufacturer_id, name FROM ".DB_PREFIX."manufacturer;";
		$result = $database->query( $sql );
		if ($result->rows) {
			foreach ($result->rows as $row) {
				$manufacturerId = $row['manufacturer_id'];
				$name = $row['name'];
				if (!isset($manufacturerIds[$name])) {
					$manufacturerIds[$name] = $manufacturerId;
				} else if ($manufacturerIds[$name] < $manufacturerId) {
					$manufacturerIds[$name] = $manufacturerId;
				}
			}
		}

		// add newly introduced manufacturers to the database
		$maxManufacturerId=0;
		foreach ($manufacturerIds as $manufacturerId) {
			$maxManufacturerId = max( $maxManufacturerId, $manufacturerId );
		}
		$sql = "INSERT INTO ".DB_PREFIX."manufacturer (manufacturer_id, name, image, sort_order) VALUES "; 
		$k = strlen( $sql );
		$first = TRUE;
		foreach ($products as $product) {
			$manufacturerName = $product['manufacturer'];
			if ($manufacturerName=="") {
				continue;
			}
			if (!isset($manufacturerIds[$manufacturerName])) {
				$maxManufacturerId += 1;
				$manufacturerId = $maxManufacturerId;
				$manufacturerIds[$manufacturerName] = $manufacturerId;
				$sql .= ($first) ? "\n" : ",\n";
				$first = FALSE;
				$sql .= "($manufacturerId, '".$database->escape($manufacturerName)."', '', 0)";
			}
		}
		$sql .= ";\n";
		if (strlen( $sql ) > $k+2) {
			$database->query( $sql );
		}
		
		// populate manufacturer_to_store table
		$storeIdsForManufacturers = array();
		foreach ($products as $product) {
			$manufacturerName = $product['manufacturer'];
			if ($manufacturerName=="") {
				continue;
			}
			$manufacturerId = $manufacturerIds[$manufacturerName];
			$storeIds = $product['store_ids'];
			if (!isset($storeIdsForManufacturers[$manufacturerId])) {
				$storeIdsForManufacturers[$manufacturerId] = array();
			}
			foreach ($storeIds as $storeId) {
				if (!in_array($storeId,$storeIdsForManufacturers[$manufacturerId])) {
					$storeIdsForManufacturers[$manufacturerId][] = $storeId;
					$sql2 = "INSERT INTO ".DB_PREFIX."manufacturer_to_store (manufacturer_id,store_id) VALUES ($manufacturerId,$storeId) ON DUPLICATE KEY UPDATE manufacturer_id = VALUES(manufacturer_id), store_id=VALUES(store_id);";
					$database->query( $sql2 );
// TODO:	Need to check into Manufactures to Store ID. Skipping this SQL for now.
				}
			}
		}
		return TRUE;
	}


	function getWeightClassIds( &$database ) {
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// find all weight classes already stored in the database
		$weightClassIds = array();
		$sql = "SELECT weight_class_id, unit FROM ".DB_PREFIX."weight_class_description WHERE language_id=$languageId;";
		$result = $database->query( $sql );
		if ($result->rows) {
			foreach ($result->rows as $row) {
				$weightClassId = $row['weight_class_id'];
				$unit = $row['unit'];
				if (!isset($weightClassIds[$unit])) {
					$weightClassIds[$unit] = $weightClassId;
				}
			}
		}

		return $weightClassIds;
	}


	function getLengthClassIds( &$database ) {
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// find all length classes already stored in the database
		$lengthClassIds = array();
		$sql = "SELECT length_class_id, unit FROM ".DB_PREFIX."length_class_description WHERE language_id=$languageId;";
		$result = $database->query( $sql );
		if ($result->rows) {
			foreach ($result->rows as $row) {
				$lengthClassId = $row['length_class_id'];
				$unit = $row['unit'];
				if (!isset($lengthClassIds[$unit])) {
					$lengthClassIds[$unit] = $lengthClassId;
				}
			}
		}

		return $lengthClassIds;
	}


	function getLayoutIds( &$database ) {
		$result = $database->query( "SELECT * FROM ".DB_PREFIX."layout" );
		$layoutIds = array();
		foreach ($result->rows as $row) {
			$layoutIds[$row['name']] = $row['layout_id'];
		}
		return $layoutIds;
	}


	protected function getAvailableStoreIds( &$database ) {
		$sql = "SELECT store_id FROM ".DB_PREFIX."store;";
		$result = $database->query( $sql );
		$storeIds = array(0);
		foreach ($result->rows as $row) {
			if (!in_array((int)$row['store_id'],$storeIds)) {
				$storeIds[] = (int)$row['store_id'];
			}
		}
		return $storeIds;
	}
	
	// TODO: Problem with storeProductsIntoDatabase()
	function storeProductsIntoDatabase( &$database, &$products ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// get pre-defined layouts
		$layoutIds = $this->getLayoutIds( $database );
		
		// get pre-defined store_ids
		$availableStoreIds = $this->getAvailableStoreIds( $database );
		
		// store or update manufacturers
		$manufacturerIds = array();
		$ok = $this->storeManufacturersIntoDatabase( $database, $products, $manufacturerIds );
		if (!$ok) {
			$database->query( 'ROLLBACK;' );
			return FALSE;
		}

		// start transaction, remove product to category links from database and disable products initially
		$sql = "START TRANSACTION;\n";
		$sql .= "TRUNCATE TABLE `".DB_PREFIX."product_to_category`;\n";
		$sql .= "SET SQL_SAFE_UPDATES=0;\n";
		$sql .= "UPDATE `".DB_PREFIX."product` SET status=0;\n";
		$this->multiquery( $database, $sql );
		
		// get weight classes
		$weightClassIds = $this->getWeightClassIds( $database );
		
		// get length classes
		$lengthClassIds = $this->getLengthClassIds( $database );
		
		//keep track of new products and updated ones
		$newones = 0;
		$updatedones = 0;
		
		// generate and execute SQL for storing the products
		foreach ($products as $product) {
			$productId = $product['product_id'];
			if ($productId==0) {$newones++;} else {$updatedones++;}
			$productName = $database->escape($product['name']);
			$categories = $product['categories'];
			$quantity = $product['quantity'];
			$model = $database->escape($product['model']);
			$manufacturerName = $product['manufacturer'];
			$manufacturerId = ($manufacturerName=="") ? 0 : $manufacturerIds[$manufacturerName];
			$imageName = $product['image'];
			$shipping = $product['shipping'];
			$shipping = ((strtoupper($shipping)=="YES") || (strtoupper($shipping)=="Y") || (strtoupper($shipping)=="TRUE")) ? 1 : 0;
			$price = trim($product['price']);
			$points = $product['points'];
			$dateAdded = $product['date_added'];
			$dateModified = $product['date_modified'];
			$dateAvailable = $product['date_available'];
			$weight = ($product['weight']=="") ? 0 : $product['weight'];
			$unit = $product['unit'];
			$weightClassId = (isset($weightClassIds[$unit])) ? $weightClassIds[$unit] : 0;
			$status = $product['status'];
			$status = ((strtoupper($status)=="TRUE") || (strtoupper($status)=="YES") || (strtoupper($status)=="ENABLED")) ? 1 : 0;
			$taxClassId = $product['tax_class_id'];
			$viewed = $product['viewed'];
			$productDescription = $database->escape($product['description']);
			$stockStatusId = ($product['stock_status_id']=="") ? "''" : $product['stock_status_id'];
			$meta_description = $database->escape($product['meta_description']);
			$length = $product['length'];
			$width = $product['width'];
			$height = $product['height'];
			$keyword = $database->escape($product['seo_keyword']);
			$lengthUnit = $product['measurement_unit'];
			$lengthClassId = (isset($lengthClassIds[$lengthUnit])) ? $lengthClassIds[$lengthUnit] : 0;
			$sku = $database->escape($product['sku']);
			$upc = $database->escape($product['upc']);
			$ean = $database->escape($product['ean']);
			$jan = $database->escape($product['jan']);
			$isbn = $database->escape($product['isbn']);
			$mpn = $database->escape($product['mpn']);
			$location = $database->escape($product['location']);
			$storeIds = $product['store_ids'];
			$layout = $product['layout'];
			$related = $product['related_ids'];
			$subtract = $product['subtract'];
			$subtract = ((strtoupper($subtract)=="TRUE") || (strtoupper($subtract)=="YES") || (strtoupper($subtract)=="ENABLED")) ? 1 : 0;
			$minimum = $product['minimum'];
			$meta_title = $model;
			$meta_keywords = $database->escape($product['meta_keywords']);
			$tags = $database->escape($product['tags']);
			$sort_order = $product['sort_order'];
			$sql  = "INSERT INTO ".DB_PREFIX."product (product_id,model,sku,upc,ean,jan,isbn,mpn,location,";
			$sql .= "quantity,stock_status_id,manufacturer_id,image,shipping,price,points,tax_class_id,date_available,weight,weight_class_id,length,width,height,length_class_id,subtract,minimum,sort_order,status,viewed,date_added,date_modified";
			$sql .= ") VALUES ";
			$sql .= "($productId,'$model','$sku','$upc','$ean','$jan','$isbn','$mpn','$location',";
			$sql .= "$quantity,$stockStatusId,$manufacturerId,'$imageName',$shipping,$price,$points,";
			$sql .= "$taxClassId,";
			$sql .= ($dateAvailable=='NOW()') ? "$dateAvailable," : "'$dateAvailable',";
			$sql .= "$weight,$weightClassId,$length,$width,$height,'$lengthClassId',";
			$sql .= "'$subtract','$minimum','$sort_order',$status,$viewed,";
			$sql .= ($dateAdded=='NOW()') ? "$dateAdded," : "'$dateAdded',";
			$sql .= ($dateModified=='NOW()') ? "$dateModified" : "'$dateModified'";
			$sql .= ")";
			$sql .= " ON DUPLICATE KEY UPDATE product_id=LAST_INSERT_ID(product_id), ";
			$sql .= "model=VALUES(model), sku=VALUES(sku), upc=VALUES(upc), ean=VALUES(ean), jan=VALUES(jan), isbn=VALUES(isbn), mpn=VALUES(mpn), location=VALUES(location), ";
			$sql .= "quantity=VALUES(quantity), stock_status_id=VALUES(stock_status_id), manufacturer_id=VALUES(manufacturer_id), image=VALUES(image), shipping=VALUES(shipping), price=VALUES(price), points=VALUES(points), tax_class_id=VALUES(tax_class_id),  date_available=VALUES(date_available), ";
			$sql .= "weight=VALUES(weight), weight_class_id=VALUES(weight_class_id), length=VALUES(length), width=VALUES(width), height=VALUES(height), length_class_id=VALUES(length_class_id), subtract=VALUES(subtract), minimum=VALUES(minimum), sort_order=VALUES(sort_order), status=VALUES(status), viewed=VALUES(viewed), date_added=VALUES(date_added), date_modified=VALUES(date_modified);";
			$database->query($sql);
//			echo $sql."<br>";
			$lastone = $database->getLastId();
//			echo "[$lastone] = product $productName <br>";
			if (!$lastone == 0) {$productId = $lastone;}
			
			$sql2 = "INSERT INTO ".DB_PREFIX."product_description (product_id,language_id,name,description,tag,meta_title,meta_description,meta_keyword) VALUES ";
			$sql2 .= "($productId,$languageId,'$productName','$productDescription','$tags','$meta_title','$meta_description','$meta_keywords')";
			$sql2 .= " ON DUPLICATE KEY UPDATE ";
			$sql2 .= "language_id=VALUES(language_id), name=VALUES(name), description=VALUES(description), tag=VALUES(tag), meta_title=VALUES(meta_title), meta_description=VALUES(meta_description), meta_keyword=VALUES(meta_keyword);";

// echo $sql2."  hh<br>";
			$database->query($sql2);
			// The product_to_category table should have been wiped out so it can update properly
			if (count($categories) > 0) {
				$sql = "INSERT INTO ".DB_PREFIX."product_to_category (product_id,category_id) VALUES ";
				$first = TRUE;
				foreach ($categories as $categoryId) {
					$sql .= ($first) ? "\n" : ",\n";
					$first = FALSE;
					$sql .= "($productId,$categoryId)";
				}
				$sql .= " ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), category_id=VALUES(category_id);";
//				echo $sql."<br>";
				$database->query($sql);
			}
			if ($keyword) {
				$sql4 = "INSERT INTO ".DB_PREFIX."url_alias (query,keyword) VALUES ('product_id=$productId','$keyword');";
//				echo $sql4."<br>";
				$database->query($sql4);
			}
			foreach ($storeIds as $storeId) {
				if (in_array((int)$storeId,$availableStoreIds)) {
					$sql6 = "INSERT INTO ".DB_PREFIX."product_to_store (product_id,store_id) VALUES ($productId,$storeId) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), store_id=VALUES(store_id);";
//					echo $sql6."<br>";
					$database->query($sql6);
				}
			}
			$layouts = array();
			foreach ($layout as $layoutPart) {
				$nextLayout = explode(':',$layoutPart);
				if ($nextLayout===FALSE) {
					$nextLayout = array( 0, $layoutPart );
				} else if (count($nextLayout)==1) {
					$nextLayout = array( 0, $layoutPart );
				}
				if ( (count($nextLayout)==2) && (in_array((int)$nextLayout[0],$availableStoreIds)) && (is_string($nextLayout[1])) ) {
					$storeId = (int)$nextLayout[0];
					$layoutName = $nextLayout[1];
					if (isset($layoutIds[$layoutName])) {
						$layoutId = (int)$layoutIds[$layoutName];
						if (!isset($layouts[$storeId])) {
							$layouts[$storeId] = $layoutId;
						}
					}
				}
			}
			foreach ($layouts as $storeId => $layoutId) {
				$sql7 = "INSERT INTO ".DB_PREFIX."product_to_layout (product_id,store_id,layout_id) VALUES ($productId,$storeId,$layoutId) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), store_id=VALUES(store_id), layout_id=VALUES(layout_id);";
//				echo $sql7."<br>";
				$database->query($sql7);
			}
			if (count($related) > 0) {
				$sql = "INSERT INTO ".DB_PREFIX."product_related (product_id,related_id) VALUES ";
				$first = TRUE;
				foreach ($related as $relatedId) {
					$sql .= ($first) ? "\n" : ",\n";
					$first = FALSE;
					$sql .= "($productId,$relatedId) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), related_id=VALUES(related_id)";
				}
				$sql .= ";";
//				echo $sql."<br>";
				$database->query($sql);
			}
		}
		
		// final commit
		$database->query("COMMIT;");

		// Store the status to be displayed on page
		$this->session->data['uploadstatus'] = "SUCCESS: $newones products was created and $updatedones products was updated.";

//		die ("[$newones] was created and [$updatedones] was updated. <br>");

		return TRUE;
	}
	

	protected function detect_encoding( $str ) {
		// auto detect the character encoding of a string
		return mb_detect_encoding( $str, 'UTF-8,ISO-8859-15,ISO-8859-1,cp1251,KOI8-R' );
	}


	// hook function for reading additional cells in class extensions
	protected function moreProductCells( $i, &$worksheet, $product ) {
		return $product;
	}
	
	  // TODO: Problem with uploadATSProducts
	function uploadATSProducts( &$reader, &$database ) {
		// find the default language id and default units
		$languageId = $this->getDefaultLanguageId($database);
		$defaultWeightUnit = $this->getDefaultWeightUnit();
		$defaultMeasurementUnit = $this->getDefaultMeasurementUnit();
//		$defaultStockStatusId = '7';
		$defaultStockStatusId = $this->config->get('config_stock_status_id');
		// TODO: Make sure the default stock status is 'In Stock'
		// TODO: Are we going to put all products as inactive before updating?
		$cnscol = $this->cnscol();
		$result = array();
		// Create the product lookup table to prevent overwriting
		$query = "SELECT model, product_id FROM oc_product";
		$result = $database->query($query);
//		$result = mysqli_query($dbc, $query);
		$prod_lookup = array();
//		while ($row = mysqli_fetch_assoc($result)) {
//		while($row = $result->fetch_assoc()) {
	// SOLUTION was found at http://stackoverflow.com/questions/14648202/php-foreach-multidimensional-array
		foreach ($result as $element) {
			foreach ($element as $row) {
		    $prod_lookup[$row["model"]] = $row["product_id"];
		}}
//		var_dump($result);
//		echo "<br><br>";
//		var_dump($prod_lookup);
//		mysql_free_result($result);
		// Create the Category lookup table
		$query = "SELECT description, category_id FROM oc_category_description";
		$result = $database->query($query);
//		$result = mysqli_query($dbc, $query);
		$cat_lookup = array();
//		while ($row = mysqli_fetch_assoc($result)) {
			// TODO: Is there a better way to set this array? Refer to: http://www.php.net/manual/en/language.types.array.php
		foreach ($result as $element) {
			foreach ($element as $row) {
				if (is_numeric($row['description'])) {
			    $cat_lookup[$row['description']] = $row['category_id'];
				} else {
			    $cat_lookup[$row['description']] = $row['category_id'];
				}
//			    $cat_lookup[$row["category_id"]] = $row["name"];
		}}
//		mysql_free_result($result);
// Create the Parent ID lookup table
/*		$query = "SELECT oc_category.category_id, oc_category.parent_id, oc_category_description.name FROM oc_category LEFT JOIN oc_category_description ON oc_category.category_id = oc_category_description.category_id;";
		$result = $database->query($query);

//		$result = mysqli_query($dbc, $query);
		$parent_name = '';
		$topcat_lookup = array();
//		while ($row = mysqli_fetch_assoc($result)) {

//	var_dump($result);
//	echo "<br><br>";
//	print_r($cat_lookup);
//	echo "<br><br>";
	
	foreach ($result as $element) {
		foreach ($element as $row) {
			if ($row['parent_id']==0) {$parent_name = $row['name'];
			} else {
				$parent_name = $cat_lookup[$row['parent_id']];
			}
//			echo $row["name"]." with catid of ".$row["category_id"]." and Parent id of ".$row["parent_id"]."|Parent name is ".$parent_name."<br>";
//			if (!$parent_name) {$parent_name = $row['name'];}
		    $topcat_lookup[$parent_name][$row['name']] = $row['category_id'];
		}
	}			
*/	
//	print_r($topcat_lookup);
//	die();
		$data = $reader->getSheet(0);
		$products = array();
		$product = array();
		$isFirstRow = TRUE;
		$isHeaderRow = TRUE;
		$i = 0;
		$k = $data->getHighestRow();
		for ($i=0; $i<$k; $i+=1) {
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			if ($isHeaderRow) {
				$isHeaderRow = FALSE;
				continue;
			}
						
			$active = $this->getCell($data, $i, $cnscol['STYLESTATUS']);
			if ($active != 'ACTIVE') {
				continue;
			}
			
//			$productId = trim(getCell($data,$i,$cnscol['STYLEID']));
//			if ($productId=="") {
//				continue;
//			}
// JOHN
			$name = $this->getCell($data, $i, $cnscol['STYLEDESCRIPTION']);
			$name = htmlentities( $name, ENT_QUOTES, $this->detect_encoding($name) );
			$topcategory = $this->getCell($data, $i, $cnscol['SUBDIV'],'');
			$topcategory = htmlentities( $topcategory, ENT_QUOTES, $this->detect_encoding($topcategory) );
			if (!$topcategory) {
			$topcategory = $this->getCell($data, $i, $cnscol['DIVISIONNAME'],'');
			$topcategory = htmlentities( $topcategory, ENT_QUOTES, $this->detect_encoding($topcategory) );
			}
//			$category = $this->getCell($data, $i, $cnscol['CATEGORY'],'');
//			$arr = explode(' ',trim($category));
//			$category = $arr[0]; //Get 1st word as the sub category name

			if (isset($cat_lookup[$topcategory])) {
				$categories = $cat_lookup[$topcategory];				
			} else {
				$categories = '0';
			}
//echo "[[".$topcategory."]]<br>";
/*			if (isset($topcat_lookup[$topcategory][$category])) {
				$categories = $topcat_lookup[$topcategory][$category];				
			} else {
				$categories = '0';
			}
*/
			$sizecategory = $this->getCell($data, $i, $cnscol['SIZE'],'');
			$sizecategory = str_replace("'","",$sizecategory);
			$sizecategory = htmlentities( $sizecategory, ENT_QUOTES, $this->detect_encoding($sizecategory) );
			if (isset($cat_lookup[$sizecategory])) {
				$categories = $categories .",". $cat_lookup[$sizecategory];				
			} 
//var_dump($cat_lookup);
//echo "[".$sizecategory."]<br>";
//die("{".$categories."}<br>");
			/*			else {
				$scategories = '0';
			}
*/

//			echo "Checking for topcat_lookup[$topcategory][$category] and found [$categories] <br>";
//			echo "Checking using cat_lookup[".$cat_lookup['15']."][".$cat_lookup['15']."] and found [".$topcat_lookup[$cat_lookup['15']][$cat_lookup['15']]."] <br>";

//echo "Compare [$topcategory] is equal to [".$cat_lookup['15']."] = ";
//$topcategory = htmlentities( $topcategory, ENT_QUOTES, $this->detect_encoding($topcategory) );
//if ($topcategory==$cat_lookup['15']) {echo "TRUE <br>";} else {echo "FALSE <br>";}

			$sku = '';
			$upc = $this->getCell($data,$i,$cnscol['UPC'],'');
			$ean = $this->getCell($data,$i,$cnscol['SEA'],'');
			$jan = 1 - $this->getCell($data,$i,$cnscol['DISCOUNT'],1);
			$isbn = '';
			$mpn = $this->getCell($data,$i,$cnscol['COLOR']);
			$location = $this->getCell($data,$i,$cnscol['COLORNAME']);;
			$quantity = $this->getCell($data,$i,$cnscol['UNITS'],'0');
			$model = $this->getCell($data,$i,$cnscol['STYLEID'],'   ');
			if (isset($prod_lookup[$model])) {$productId = $prod_lookup[$model];} 
			else {$productId = '0';}
			$manufacturer = $this->getCell($data,$i,$cnscol['DIVISION']);
			$imageName = 'http://www.ronlynn.com/images/wang/'.$model.'.jpg';
			$shipping = 'no';
			$price = $this->getCell($data,$i,$cnscol['ATSPRICE'],'0.00');
			$price = $price;
			$points = 0;
			$dateAdded = '';
			$dateModified = '';
			$dateAvailable = '';
//			$dateAdded = $this->getCell($data,$i,$j++);
			$dateAdded = ((is_string($dateAdded)) && (strlen($dateAdded)>0)) ? $dateAdded : "NOW()";
//			$dateModified = $this->getCell($data,$i,$j++);
			$dateModified = ((is_string($dateModified)) && (strlen($dateModified)>0)) ? $dateModified : "NOW()";
//			$dateAvailable = $this->getCell($data,$i,$j++);
			$dateAvailable = ((is_string($dateAvailable)) && (strlen($dateAvailable)>0)) ? $dateAvailable : "NOW()";
			$weight = 0;
			$unit = $defaultWeightUnit;
//			$length = $this->getCell($data,$i,$j++,'0');
//			$width = $this->getCell($data,$i,$j++,'0');
//			$height = $this->getCell($data,$i,$j++,'0');
			$length = 0;
			$width = 0;
			$height = 0;
			$measurementUnit = $defaultMeasurementUnit;
			$status = 'true';
			$taxClassId = '11';
			$viewed = 0;
			$langId = '1';

//			if ($langId!=$languageId) {
//				continue;
//			}

			$keyword = '';
			$style_color_name = $this->getCell($data,$i,$cnscol['COLORNAME']);
			$category_low = $this->getCell($data,$i,$cnscol['GROUP']);
			$size_scale = $this->getCell($data,$i,$cnscol['SIZESCALE']);
			$size = $this->getCell($data,$i,$cnscol['SIZE']);
			
			$description = $this->getCell($data,$i,$cnscol['STYLEDESCRIPTION']);
			$description .= "<br><p><b>STYLE NAME: </b>" . $model . "</p><br>";
//			$description .= "<b>  COLOR: </b>" . $style_color_name . "</p><br>";
//			$description .= "<br><p><b>SIZE SCALE: </b>" . $size_scale . "</p><b>  SIZE: </b>" . $size . "</p><br>";			
			$description = htmlentities( $description, ENT_QUOTES, $this->detect_encoding($description) );
//			$description = '';
			$meta_description = '';
			$meta_description = htmlentities( $meta_description, ENT_QUOTES, $this->detect_encoding($meta_description) );
			$meta_keywords = '';
			$meta_keywords = htmlentities( $meta_keywords, ENT_QUOTES, $this->detect_encoding($meta_keywords) );
			// TODO: Confirm stockstatus is In Stock if quantity is > 0
			$stockStatusId = $defaultStockStatusId;
			$storeIds = '0';
			$layout = '';
			$related = '';
			$tags = '';
			$tags = htmlentities( $tags, ENT_QUOTES, $this->detect_encoding($tags) );
			$sort_order = 0;
			$subtract = 'true';
			$minimum = 1;

			$product = array();
			$product['product_id'] = $productId;
			$product['name'] = $name;
			$categories = trim( $this->clean($categories, FALSE) );
			$product['categories'] = ($categories=="") ? array() : explode( ",", $categories );
			if ($product['categories']===FALSE) {
				$product['categories'] = array();
			}
			$product['quantity'] = $quantity;
			$product['model'] = $model;
			$product['manufacturer'] = $manufacturer;
			$product['image'] = $imageName;
			$product['shipping'] = $shipping;
			$product['price'] = $price;
			$product['points'] = $points;
			$product['date_added'] = $dateAdded;
			$product['date_modified'] = $dateModified;
			$product['date_available'] = $dateAvailable;
			$product['weight'] = $weight;
			$product['unit'] = $unit;
			$product['status'] = $status;
			$product['tax_class_id'] = $taxClassId;
			$product['viewed'] = $viewed;
			$product['language_id'] = $languageId;
			$product['description'] = $description;
			$product['stock_status_id'] = $stockStatusId;
			$product['meta_description'] = $meta_description;
			$product['length'] = $length;
			$product['width'] = $width;
			$product['height'] = $height;
			$product['seo_keyword'] = $keyword;
			$product['measurement_unit'] = $measurementUnit;
			$product['sku'] = $sku;
			$product['upc'] = $upc;
			$product['ean'] = $ean;
			$product['jan'] = $jan;
			$product['isbn'] = $isbn;
			$product['mpn'] = $mpn;
			$product['location'] = $location;
			$storeIds = trim( $this->clean($storeIds, FALSE) );
			$product['store_ids'] = ($storeIds=="") ? array() : explode( ",", $storeIds );
			if ($product['store_ids']===FALSE) {
				$product['store_ids'] = array();
			}
			$product['related_ids'] = ($related=="") ? array() : explode( ",", $related );
			if ($product['related_ids']===FALSE) {
				$product['related_ids'] = array();
			}
			$product['layout'] = ($layout=="") ? array() : explode( ",", $layout );
			if ($product['layout']===FALSE) {
				$product['layout'] = array();
			}
			$product['subtract'] = $subtract;
			$product['minimum'] = $minimum;
			$product['meta_keywords'] = $meta_keywords;
			$product['tags'] = $tags;
			$product['sort_order'] = $sort_order;
			$products[$model] = $this->moreProductCells( $i, $data, $product );
		}

		// TODO: Adding in specials for employee pricing
		$specials = array();
		$i = 1;
		foreach ($products as $specialproduct) {
				$productId = $specialproduct['product_id'];
				$price = trim($specialproduct['price']);
//	echo "[".$price."] <br>";
			// TODO: specialme
			$discount = $specialproduct['jan'];
			$specials[$i] = array();
			$specials[$i]['product_id'] = $productId;
			$specials[$i]['customer_group'] = '1';
			$specials[$i]['priority'] = '1';
			$specials[$i]['price'] = $price * $discount;
//			$specials[$i]['price'] = $price * 0.7;
//	echo "{".$specials[$i]['price']."} <br>";
			$specials[$i]['date_start'] = 'CURDATE()';
			$specials[$i]['date_end'] = 'CURDATE() + INTERVAL 1 YEAR';
			$i++;
		}
		$specialok = $this->storeSpecialsIntoDatabase( $database, $specials );
		
		return $this->storeProductsIntoDatabase( $database, $products );
	}

	function uploadProducts( &$reader, &$database ) {
		// find the default language id and default units
		$languageId = $this->getDefaultLanguageId($database);
		$defaultWeightUnit = $this->getDefaultWeightUnit();
		$defaultMeasurementUnit = $this->getDefaultMeasurementUnit();
		$defaultStockStatusId = $this->config->get('config_stock_status_id');
		
		$data = $reader->getSheet(1);
		$products = array();
		$product = array();
		$isFirstRow = TRUE;
		$i = 0;
		$k = $data->getHighestRow();
		for ($i=0; $i<$k; $i+=1) {
			$j = 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$name = $this->getCell($data,$i,$j++);
			$name = htmlentities( $name, ENT_QUOTES, $this->detect_encoding($name) );
			$categories = $this->getCell($data,$i,$j++);
			$sku = $this->getCell($data,$i,$j++,'');
			$upc = $this->getCell($data,$i,$j++,'');
			$ean = $this->getCell($data,$i,$j++,'');
			$jan = $this->getCell($data,$i,$j++,'');
			$isbn = $this->getCell($data,$i,$j++,'');
			$mpn = $this->getCell($data,$i,$j++,'');
			$location = $this->getCell($data,$i,$j++,'');
			$quantity = $this->getCell($data,$i,$j++,'0');
			$model = $this->getCell($data,$i,$j++,'   ');
			$manufacturer = $this->getCell($data,$i,$j++);
			$imageName = $this->getCell($data,$i,$j++);
			$shipping = $this->getCell($data,$i,$j++,'no');
			$price = $this->getCell($data,$i,$j++,'0.00');
			$points = $this->getCell($data,$i,$j++,'0');
			$dateAdded = $this->getCell($data,$i,$j++);
			$dateAdded = ((is_string($dateAdded)) && (strlen($dateAdded)>0)) ? $dateAdded : "NOW()";
			$dateModified = $this->getCell($data,$i,$j++);
			$dateModified = ((is_string($dateModified)) && (strlen($dateModified)>0)) ? $dateModified : "NOW()";
			$dateAvailable = $this->getCell($data,$i,$j++);
			$dateAvailable = ((is_string($dateAvailable)) && (strlen($dateAvailable)>0)) ? $dateAvailable : "NOW()";
			$weight = $this->getCell($data,$i,$j++,'0');
			$unit = $this->getCell($data,$i,$j++,$defaultWeightUnit);
			$length = $this->getCell($data,$i,$j++,'0');
			$width = $this->getCell($data,$i,$j++,'0');
			$height = $this->getCell($data,$i,$j++,'0');
			$measurementUnit = $this->getCell($data,$i,$j++,$defaultMeasurementUnit);
			$status = $this->getCell($data,$i,$j++,'true');
			$taxClassId = $this->getCell($data,$i,$j++,'0');
			$viewed = $this->getCell($data,$i,$j++,'0');
			$langId = $this->getCell($data,$i,$j++,'1');
			if ($langId!=$languageId) {
				continue;
			}
			$keyword = $this->getCell($data,$i,$j++);
			$description = $this->getCell($data,$i,$j++);
			$description = htmlentities( $description, ENT_QUOTES, $this->detect_encoding($description) );
			$meta_description = $this->getCell($data,$i,$j++);
			$meta_description = htmlentities( $meta_description, ENT_QUOTES, $this->detect_encoding($meta_description) );
			$meta_keywords = $this->getCell($data,$i,$j++);
			$meta_keywords = htmlentities( $meta_keywords, ENT_QUOTES, $this->detect_encoding($meta_keywords) );
			$stockStatusId = $this->getCell($data,$i,$j++,$defaultStockStatusId);
			$storeIds = $this->getCell($data,$i,$j++);
			$layout = $this->getCell($data,$i,$j++);
			$related = $this->getCell($data,$i,$j++);
			$tags = $this->getCell($data,$i,$j++);
			$tags = htmlentities( $tags, ENT_QUOTES, $this->detect_encoding($tags) );
			$sort_order = $this->getCell($data,$i,$j++,'0');
			$subtract = $this->getCell($data,$i,$j++,'true');
			$minimum = $this->getCell($data,$i,$j++,'1');
			$product = array();
			$product['product_id'] = $productId;
			$product['name'] = $name;
			$categories = trim( $this->clean($categories, FALSE) );
			$product['categories'] = ($categories=="") ? array() : explode( ",", $categories );
			if ($product['categories']===FALSE) {
				$product['categories'] = array();
			}
			$product['quantity'] = $quantity;
			$product['model'] = $model;
			$product['manufacturer'] = $manufacturer;
			$product['image'] = $imageName;
			$product['shipping'] = $shipping;
			$product['price'] = $price;
			$product['points'] = $points;
			$product['date_added'] = $dateAdded;
			$product['date_modified'] = $dateModified;
			$product['date_available'] = $dateAvailable;
			$product['weight'] = $weight;
			$product['unit'] = $unit;
			$product['status'] = $status;
			$product['tax_class_id'] = $taxClassId;
			$product['viewed'] = $viewed;
			$product['language_id'] = $languageId;
			$product['description'] = $description;
			$product['stock_status_id'] = $stockStatusId;
			$product['meta_description'] = $meta_description;
			$product['length'] = $length;
			$product['width'] = $width;
			$product['height'] = $height;
			$product['seo_keyword'] = $keyword;
			$product['measurement_unit'] = $measurementUnit;
			$product['sku'] = $sku;
			$product['upc'] = $upc;
			$product['ean'] = $ean;
			$product['jan'] = $jan;
			$product['isbn'] = $isbn;
			$product['mpn'] = $mpn;
			$product['location'] = $location;
			$storeIds = trim( $this->clean($storeIds, FALSE) );
			$product['store_ids'] = ($storeIds=="") ? array() : explode( ",", $storeIds );
			if ($product['store_ids']===FALSE) {
				$product['store_ids'] = array();
			}
			$product['related_ids'] = ($related=="") ? array() : explode( ",", $related );
			if ($product['related_ids']===FALSE) {
				$product['related_ids'] = array();
			}
			$product['layout'] = ($layout=="") ? array() : explode( ",", $layout );
			if ($product['layout']===FALSE) {
				$product['layout'] = array();
			}
			$product['subtract'] = $subtract;
			$product['minimum'] = $minimum;
			$product['meta_keywords'] = $meta_keywords;
			$product['tags'] = $tags;
			$product['sort_order'] = $sort_order;
			$products[$productId] = $this->moreProductCells( $i, $j, $data, $product );
		}
		return $this->storeProductsIntoDatabase( $database, $products );
	} 


	function storeCategoriesIntoDatabase( &$database, &$categories ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// get pre-defined layouts
		$layoutIds = $this->getLayoutIds( $database );
		
		// get pre-defined store_ids
		$availableStoreIds = $this->getAvailableStoreIds( $database );
		
		// generate and execute SQL for inserting the categories
		foreach ($categories as $category) {
			$categoryId = $category['category_id'];
			$imageName = $category['image'];
			$parentId = $category['parent_id'];
			$top = $category['top'];
			$top = ((strtoupper($top)=="TRUE") || (strtoupper($top)=="YES") || (strtoupper($top)=="ENABLED")) ? 1 : 0;
			$columns = $category['columns'];
			$sortOrder = $category['sort_order'];
			$dateAdded = $category['date_added'];
			$dateModified = $category['date_modified'];
			$languageId = $category['language_id'];
			$name = $database->escape($category['name']);
			$description = $database->escape($category['description']);
			$meta_title = $database->escape($category['meta_title']);
			$meta_description = $database->escape($category['meta_description']);
			$meta_keywords = $database->escape($category['meta_keywords']);
			$keyword = $database->escape($category['seo_keyword']);
			$storeIds = $category['store_ids'];
			$layout = $category['layout'];
			$status = $category['status'];
			$status = ((strtoupper($status)=="TRUE") || (strtoupper($status)=="YES") || (strtoupper($status)=="ENABLED")) ? 1 : 0;
			$sql2 = "INSERT INTO ".DB_PREFIX."category (category_id, image, parent_id, top, `column`, sort_order, status, date_added, date_modified) VALUES ";
			$sql2 .= "( $categoryId, '$imageName', $parentId, $top, $columns, $sortOrder, ";
			$sql2 .= " $status, ";
			$sql2 .= ($dateAdded=='NOW()') ? "$dateAdded," : "'$dateAdded',";
			$sql2 .= ($dateModified=='NOW()') ? "$dateModified," : "'$dateModified')";
			$database->query( $sql2 );
			$categoryId = $database->getLastId();
			
			$sql3 = "INSERT INTO ".DB_PREFIX."category_description (category_id, language_id, name, description, meta_title, meta_description, meta_keyword) VALUES ";
			$sql3 .= "( $categoryId, $languageId, '$name', '$description', '$meta_title', '$meta_description', '$meta_keywords' );";
			$database->query( $sql3 );
			
			if ($keyword) {
				$sql5 = "INSERT INTO ".DB_PREFIX."url_alias (query,keyword) VALUES ('category_id=$categoryId','$keyword');";
				$database->query($sql5);
			}
			foreach ($storeIds as $storeId) {
				if (in_array((int)$storeId,$availableStoreIds)) {
					$sql6 = "INSERT INTO ".DB_PREFIX."category_to_store (category_id,store_id) VALUES ($categoryId,$storeId);";
					$database->query($sql6);
				}
			}
			$layouts = array();
			foreach ($layout as $layoutPart) {
				$nextLayout = explode(':',$layoutPart);
				if ($nextLayout===FALSE) {
					$nextLayout = array( 0, $layoutPart );
				} else if (count($nextLayout)==1) {
					$nextLayout = array( 0, $layoutPart );
				}
				if ( (count($nextLayout)==2) && (in_array((int)$nextLayout[0],$availableStoreIds)) && (is_string($nextLayout[1])) ) {
					$storeId = (int)$nextLayout[0];
					$layoutName = $nextLayout[1];
					if (isset($layoutIds[$layoutName])) {
						$layoutId = (int)$layoutIds[$layoutName];
						if (!isset($layouts[$storeId])) {
							$layouts[$storeId] = $layoutId;
						}
					}
				}
			}
			foreach ($layouts as $storeId => $layoutId) {
				$sql7 = "INSERT INTO ".DB_PREFIX."category_to_layout (category_id,store_id,layout_id) VALUES ($categoryId,$storeId,$layoutId);";
				$database->query($sql7);
			}
		}
		
		// TODO: Add this code to do repair of category for faster lookups
		// restore category paths for faster lookups on the frontend
		$this->load->model( 'catalog/category' );
		$this->model_catalog_category->repairCategories(0);

		// final commit
		$database->query( "COMMIT;" );
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreCategoryCells( $i, &$worksheet, $category ) {
		return $category;
	}

	function cnscol() {
		return array ('WHSE' => 1, 'DIVISION' => 2, 'DIVISIONNAME' => 3, 'SEA' => 4, 'STYLEID' => 5, 'STYLEDESCRIPTION' => 6, 'FABRIC' => 7, 'COLOR' => 9, 'COLORNAME' => 10, 'FW' => 11, 'STYLESTATUS' => 12, 'SIZESCALE' => 13, 'SIZE' =>	14, 'UPC' => 15, 'GROUP' => 16, 'CATEGORY' => 17, 'SUBDIV' => 18, 'UNITCOST' => 20, 'COSTTYPE' => 21, 'UNITS' => 53, 'ATSPRICE' => 19, 'COST' => 55, 'DISCOUNT' => 65 );
	}

	function subdiv_table() {
		return array ( 'Ready To Wear'=>1, 'RTW Knits'=>11, 'Women\'s Handbags'=>20, 'Men\'s Bags'=>21, 'Women\'s Footwear'=>30, 'Men\'s Footwear'=>31, 'T Women'=>40, 'Denim X Alexander Wang'=>41, 'T Men'=>50, 'Men\'s RTW'=>60, 'Men\'s RTW Knits'=>61, 'Women\'s SLG'=>70, 'Men\'s SLG'=>71, 'Women\'s Accessories'=>72, 'Men\'s Accessories'=>73, 'Accessories'=>90, 'Other Small Objects'=>91, 'GIFTING'=>93, 'Licensed Products'=>95, 'Sunglasses'=>98 );
	}
	
	function uploadCategories( &$reader, &$database ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		// Create the Category lookup table to prevent overwriting
		$query = "SELECT name, category_id FROM oc_category_description";
		$result = $database->query($query);
//		$result = mysqli_query($dbc, $query);
		$cat_lookup = array();
		while ($row = mysqli_fetch_assoc($result)) {
		    $cat_lookup[$row['name']] = $row['category_id'];
		}
		
		$query = "SELECT category_id, parent_id FROM oc_category";
		$result = $database->query($query);
//		$result = mysqli_query($dbc, $query);
		$parent_lookup = array();
		while ($row = mysqli_fetch_assoc($result)) {
		    $parent_lookup[$row['category_id']] = $row['parent_id'];
		}
		
		$cnscol = $this->cnscol();
		$data = $reader->getSheet(0);
		$categories = array();
		$catnames = array();
		$isFirstRow = TRUE;
		$i = 0;
		$k = $data->getHighestRow();
		for ($i=1; $i<$k; $i+=1) {
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$sizecat = $this->getCell($data,$i,$cnscol['SIZE']);
			$divnum = $this->getCell($data,$i,$cnscol['DIVISION']);
			$name = $this->getCell($data,$i,$cnscol['SUBDIV']);
			$name2 = $this->getCell($data,$i,$cnscol['CATEGORY']);
			$arr = explode(' ',trim($name2));
			$name2 = $arr[0]; //Get 1st word as the sub category name
			$name = htmlentities( $name, ENT_QUOTES, $this->detect_encoding($name) );

			var_dump($catnames);
			die();
			
			if (!$name) { continue; }
			if (array_key_exists($name, $catnames)) {
				$name = $name2;
				continue;
			} else {
				$catnames[$name] = '0';
			}
			if (isset($cat_lookup[$name])) { 
				$categoryId = $cat_lookup[$name];
				continue;	
			} else { 
				$categoryId = 0; }

				// TODO: Look up Parent ID and sub category it. Be sure to only take the 1st word as name.
			
			$parentId = $catnames[$name];
			$top = 'FALSE';
			$columns = '1';
			$sortOrder = '0';
			$imageName = '';
			$dateAdded = date('Y-m-d H:i:s');
			$dateAdded = ((is_string($dateAdded)) && (strlen($dateAdded)>0)) ? $dateAdded : "NOW()";
			$dateModified = date('Y-m-d H:i:s');
			$dateModified = ((is_string($dateModified)) && (strlen($dateModified)>0)) ? $dateModified : "NOW()";
			$langId = '1';
			$keyword = '';
			$description = $this->getCell($data,$i,$cnscol['SUBDIV']);
			$description = htmlentities( $description, ENT_QUOTES, $this->detect_encoding($description) );
			$meta_title = '';
			$meta_title = htmlentities( $meta_title, ENT_QUOTES, $this->detect_encoding($meta_title) );
			$meta_description = '';
			$meta_description = htmlentities( $meta_description, ENT_QUOTES, $this->detect_encoding($meta_description) );
			$meta_keywords = '';
			$meta_keywords = htmlentities( $meta_keywords, ENT_QUOTES, $this->detect_encoding($meta_keywords) );
			$storeIds = '0';
			$layout = '';
			$status = 'true';
			$category = array();
			$category['category_id'] = $categoryId;
			$category['image'] = $imageName;
			$category['parent_id'] = $parentId;
			$category['sort_order'] = $sortOrder;
			$category['date_added'] = $dateAdded;
			$category['date_modified'] = $dateModified;
			$category['language_id'] = $languageId;
			$category['name'] = $name;
			$category['top'] = $top;
			$category['columns'] = $columns;
			$category['description'] = $description;
			$category['meta_title'] = $meta_title;
			$category['meta_description'] = $meta_description;
			$category['meta_keywords'] = $meta_keywords;
			$category['seo_keyword'] = $keyword;
			$storeIds = trim( $this->clean($storeIds, FALSE) );
			$category['store_ids'] = ($storeIds=="") ? array() : explode( ",", $storeIds );
			if ($category['store_ids']===FALSE) {
				$category['store_ids'] = array();
			}
			$category['layout'] = ($layout=="") ? array() : explode( ",", $layout );
			if ($category['layout']===FALSE) {
				$category['layout'] = array();
			}
			$category['status'] = $status;
			$categories[$name] = $this->moreCategoryCells( $i, $data, $category );
		}
		return $this->storeCategoriesIntoDatabase( $database, $categories );
	}


	function storeOptionsIntoDatabase( &$database, &$options ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// reuse old options and old option_values where possible
		$optionIds = array();       // indexed by [name][type]
		$optionValueIds = array();  // indexed by [name][type][value][image]
		$maxOptionSortOrder = 0;
		$maxOptionId = 0;
		$maxOptionValueId = 0;
		$sql  = "SELECT o.*, od.name, ovd.option_value_id, ovd.name AS value, ov.image FROM ".DB_PREFIX."option o ";
		$sql .= "INNER JOIN ".DB_PREFIX."option_description od ON od.option_id=o.option_id AND od.language_id=$languageId ";
		$sql .= "LEFT JOIN ".DB_PREFIX."option_value ov ON ov.option_id=o.option_id ";
		$sql .= "LEFT JOIN  ".DB_PREFIX."option_value_description ovd ON ovd.option_value_id=ov.option_value_id AND ovd.language_id=$languageId ";
		$result = $database->query( $sql );
		foreach ($result->rows as $row) {
			$name = $row['name'];
			$type = $row['type'];
			$value = $row['value'];
			$optionId = $row['option_id'];
			$optionValueId = $row['option_value_id'];
			$image = $row['image'];
			$optionSortOrder = $row['sort_order'];
			
			if ($maxOptionId < $optionId) {
				$maxOptionId = $optionId;
			}
			if ($maxOptionValueId < $optionValueId) {
				$maxOptionValueId = $optionValueId;
			}
			if ($maxOptionSortOrder < $optionSortOrder) {
				$maxOptionSortOrder = $optionSortOrder;
			}
			if (!isset($optionIds[$name])) {
				$optionIds[$name] = array();
			}
			if (!isset($optionIds[$name][$type])) {
				$optionIds[$name][$type] = $optionId;
			}
			if (!isset($optionValueIds[$name])) {
				$optionValueIds[$name] = array();
			}
			if (!isset($optionValueIds[$name][$type])) {
				$optionValueIds[$name][$type] = array();
			}
			if (!isset($optionValueIds[$name][$type][$value])) {
				$optionValueIds[$name][$type][$value] = array();
			}
			if (!isset($optionValueIds[$name][$type][$value][$image])) {
				$optionValueIds[$name][$type][$value][$image] = $optionValueId;
			}
		}

		// start transaction, remove product options and product option values from database
		$sql = "START TRANSACTION;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_option;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_option_value;\n";
		$this->multiquery( $database, $sql );
					// Need to add the UPC for color size combination
				$upccheck = array();
				$sql = "SELECT upc FROM oc_product_option_value;";
				$upccheck = $database->query($sql);
				$sql = "";
				if (!$upccheck) {
					$sql ="ALTER TABLE oc_product_option_value ADD upc VARCHAR(16);";
					$database->query($sql);
					$sql="";
				}
		// add product options and product option values to the database
		$productOptionIds = array(); // indexed by [product_id][option_id]
		$maxProductOptionId = 0;
		$maxProductOptionValueId = 0;

		foreach ($options as $option) {
			$productId = $option['product_id'];
			$langId = $option['language_id'];
			$name = $option['option'];
			$type = $option['type'];
			$value = $option['value'];
			$image = $option['image'];
			$upc = $option['upc'];
			$required = $option['required'];
			$required = ((strtoupper($required)=="TRUE") || (strtoupper($required)=="YES") || (strtoupper($required)=="ENABLED")) ? 1 : 0;
			if (!isset($optionIds[$name])) {
				$optionIds[$name] = array();
			}
			if (!isset($optionIds[$name][$type])) {
				$maxOptionId += 1;
				$optionId = $maxOptionId;
				$optionIds[$name][$type] = $optionId;
				$maxOptionSortOrder += 1;
				$sql = "INSERT INTO ".DB_PREFIX."option (option_id,type,sort_order) VALUES ($optionId,'$type',$maxOptionSortOrder);";
				$database->query( $sql );
				$sql = "INSERT INTO ".DB_PREFIX."option_description (option_id,language_id,name) VALUES ($optionId,$langId,'".$database->escape($name)."');";
				$database->query( $sql);
			}
			if (($type=='select') || ($type=='checkbox') || ($type=='radio') || ($type=='image')) {
				if (!isset($optionValueIds[$name])) {
					$optionValueIds[$name] = array();
				}
				if (!isset($optionValueIds[$name][$type])) {
					$optionValueIds[$name][$type] = array();
				}
				if (!isset($optionValueIds[$name][$type][$value])) {
					$optionValueIds[$name][$type][$value] = array();
				}
				if (!isset($optionValueIds[$name][$type][$value][$image])) {
					$maxOptionValueId += 1;
					$optionValueId = $maxOptionValueId;
					$optionValueIds[$name][$type][$value][$image] = $optionValueId;
					$sortOrder = ($option['sort_order'] == '') ? 0 : $option['sort_order'];
					$optionId = $optionIds[$name][$type];
					$sql  = "INSERT INTO ".DB_PREFIX."option_value (option_value_id,option_id,image,sort_order) VALUES ";
					$sql .= "($optionValueId,$optionId,'".$database->escape($image)."',$sortOrder);";
					$database->query( $sql );
					$sql  = "INSERT INTO ".DB_PREFIX."option_value_description (option_value_id,language_id,option_id,name) VALUES ";
					$sql .= "($optionValueId,$langId,$optionId,'".$database->escape($value)."');";
					$database->query( $sql );
				} else {
					$optionValueId = $optionValueIds[$name][$type][$value][$image];
					$sortOrder = ($option['sort_order'] == '') ? 0 : $option['sort_order'];
					$optionId = $optionIds[$name][$type];
					$sql  = "UPDATE ".DB_PREFIX."option_value SET image='".$database->escape($image)."', sort_order=$sortOrder ";
					$sql .= "WHERE option_value_id=$optionValueId AND option_id=$optionId";
					$database->query( $sql );
				}
			}
			if (!isset($productOptionIds[$productId])) {
				$productOptionIds[$productId] = array();
			}
			$optionId = $optionIds[$name][$type];
			if (!isset($productOptionIds[$productId][$optionId])) {
				$maxProductOptionId += 1;
				$productOptionId = $maxProductOptionId;
				$productOptionIds[$productId][$optionId] = $productOptionId;
				if (($type!='select') && ($type!='checkbox') && ($type!='radio') && ($type!='image')) {
					$productOptionValue = $value;
				} else {
					$productOptionValue = '';
				}
				$sql  = "INSERT INTO ".DB_PREFIX."product_option (product_option_id,product_id,option_id,value,required) VALUES ";
				$sql .= "($productOptionId,$productId,$optionId,'".$database->escape($productOptionValue)."',$required);";
				$database->query( $sql );
			}
			if (($type=='select') || ($type=='checkbox') || ($type=='radio') || ($type=='image')) {
				$quantity = $option['quantity'];
				$subtract = $option['subtract'];
				$subtract = ((strtoupper($subtract)=="TRUE") || (strtoupper($subtract)=="YES") || (strtoupper($subtract)=="ENABLED")) ? 1 : 0;
				$price = $option['price'];
				$pricePrefix = $option['price_prefix'];
				$points = $option['points'];
				$pointsPrefix = $option['points_prefix'];
				$weight = $option['weight'];
				$weightPrefix = $option['weight_prefix'];
				$upc = $option['upc'];
				$sortOrder= $option['sort_order'];
				$maxProductOptionValueId += 1;
				$productOptionValueId = $maxProductOptionValueId;
				$optionId = $optionIds[$name][$type];
				$optionValueId = $optionValueIds[$name][$type][$value][$image];
				$productOptionId = $productOptionIds[$productId][$optionId];
				$sql  = "INSERT INTO ".DB_PREFIX."product_option_value (product_option_value_id,product_option_id,product_id,option_id,option_value_id,quantity,subtract,price,price_prefix,points,points_prefix,weight,weight_prefix,upc) VALUES ";
				$sql .= "($productOptionValueId,$productOptionId,$productId,$optionId,$optionValueId,$quantity,$subtract,$price,'$pricePrefix',$points,'$pointsPrefix',$weight,'$weightPrefix','$upc');";
//echo $sql."<br>";
				$database->query( $sql );
			}
		}
				// TUNA  Adjusting the total quantity for a particular model.
		// generate and execute SQL for storing the total product balances
		//		TUNA
		$result = array();
		$queryt = "SELECT c.product_id, c.quantity, sum(b.quantity) totalqty FROM oc_product_option_value b LEFT JOIN oc_product c ON b.product_id = c.product_id GROUP BY c.model;";
		$resultt = $database->query($queryt);
//		$totalprod_lookup = array();
				$queryt = "CREATE TEMPORARY TABLE oc_temp_product LIKE oc_product;\n";
				$queryt .= "INSERT oc_temp_product SELECT * FROM oc_product;\n";
				$this->multiquery( $database, $queryt );
//echo $queryt."<br>";
		foreach ($resultt as $element) {
			foreach ($element as $row) {
//				$totalprod_lookup[$row["product_id"]] = (INT)str_replace("'","",$row["totalqty"]);
				if ((INT)str_replace("'","",$row["quantity"]) <> (INT)str_replace("'","",$row["totalqty"])) {
					$sql4 =	"UPDATE oc_product ";
					$sql4 .= "INNER JOIN oc_temp_product ON oc_temp_product.product_id = oc_product.product_id ";
					$sql4 .= "SET oc_product.quantity = ".(INT)str_replace("'","",$row["totalqty"])." ";
					$sql4 .= "WHERE oc_product.product_id = ".$row["product_id"].";";
					$database->query($sql4);
//echo $sql4."<br>";
				}
			}
		}
		$database->query("DROP TEMPORARY TABLE oc_temp_product;");
//echo "DROP TEMPORARY TABLE oc_temp_product;";
		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreOptionCells( $i, &$worksheet, $option ) {
		return $option;
	}


	function uploadOptions( &$reader, &$database ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		$cnscol = $this->cnscol();
		$result = array();
		$query = "SELECT model, product_id FROM oc_product";
		$result = $database->query($query);
		$prod_lookup = array();
		foreach ($result as $element) {
			foreach ($element as $row) {
		    $prod_lookup[$row["model"]] = $row["product_id"];
		}}
		$data = $reader->getSheet(0);
		$options = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=1; $i<$k; $i+=1) {
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$model = $this->getCell($data,$i,$cnscol['STYLEID'],'   ');
			if (isset($prod_lookup[$model])) {$productId = $prod_lookup[$model];} 
			else {$productId = '';}
			if ($productId=="") {
				continue;
			}
			$langId = '1';
/*			$langId = $this->getCell($data,$i,$j++);
			if ($langId!=$languageId) {
				continue;
			}  */
			// 	function cnscol() {
//		return array ('WHSE' => 1, 'DIVISION' => 2, 'DIVISIONNAME' => 3, 'SEA' => 4, 'STYLEID' => 5, 'STYLEDESCRIPTION' => 6, 'FABRIC' => 7, 'COLOR' => 8, 'COLORNAME' => 10, 'FW' => 11, 'STYLESTATUS' => 12, 'SIZESCALE' => 13, 'SIZE' =>	14, 'UPC' => 15, 'GROUP' => 16, 'CATEGORY' => 17, 'SUBDIV' => 18, 'UNITCOST' => 20, 'COSTTYPE' => 21, 'UNITS' => 52, 'ATSPRICE' => 53, 'COST' => 54 );
//	}
			$option = 'COLOR-SIZE';
			$type = 'select';
			$oval1 = $this->getCell($data,$i,$cnscol['COLORNAME'],'');
			$oval2 = $this->getCell($data,$i,$cnscol['SIZE'],'');
			$oval3 = $this->getCell($data,$i,$cnscol['SIZESCALE'],'');			
			$value = $oval1.' - Size '.$oval2.' : '.$oval3;
			$image = '';
			$required = 'true';
			$quantity = $this->getCell($data,$i,$cnscol['UNITS'],'0');
			$subtract = 'true';
			$upc = $this->getCell($data,$i,$cnscol['UPC'],'0');
			$price = $this->getCell($data,$i,$cnscol['ATSPRICE'],'0');
//			$price = $price * 0.7;
			$price = '0';
			$pricePrefix = '+';
			$points = '0';
			$pointsPrefix = '+';
			$weight = '0.00';
			$weightPrefix = '+';
			$sortOrder = '0';
			$options[$i] = array();
			$options[$i]['product_id'] = $productId;
			$options[$i]['language_id'] = $languageId;
			$options[$i]['option'] = $option;
			$options[$i]['type'] = $type;
			$options[$i]['value'] = $value;
			$options[$i]['image'] = $image;	
			$options[$i]['required'] = $required;
			if (($type=='select') || ($type=='checkbox') || ($type=='radio') || ($type=='image')) {
				$options[$i]['quantity'] = $quantity;
				$options[$i]['subtract'] = $subtract;
				$options[$i]['price'] = $price;
				$options[$i]['price_prefix'] = $pricePrefix;
				$options[$i]['points'] = $points;
				$options[$i]['points_prefix'] = $pointsPrefix;
				$options[$i]['weight'] = $weight;
				$options[$i]['weight_prefix'] = $weightPrefix;
				$options[$i]['upc'] = $upc;		
				$options[$i]['sort_order'] = $sortOrder;

//				if ($options[$i]['quantity'] > 0) {			
//					$updatequery = "UPDATE oc_product SET quantity = quantity + ".$options[$i]['quantity']." WHERE product_id = ".$options[$i]['product_id'];
//					$database->query( $updatequery );
//				}
			}
			$options[$i] = $this->moreOptionCells( $i, $data, $options[$i] );
		}
		return $this->storeOptionsIntoDatabase( $database, $options );
	}


	function storeAttributesIntoDatabase( &$database, &$attributes ) {
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		// reuse old attribute_groups and attributes where possible
		$attributeGroupIds = array();    // indexed by [group]
		$attributeIds = array();         // indexed by [group][name]
		$maxAttributeGroupSortOrder = 0;
		$maxAttributeGroupId = 0;
		$maxAttributeId = 0;
		$maxAttributeSortOrders = array(); // index by [group]
		$sql  = "SELECT ag.attribute_group_id, ag.sort_order AS group_sort_order, agd.name AS group, a.sort_order, ad.attribute_id, ad.name FROM ".DB_PREFIX."attribute_group ag ";
		$sql .= "INNER JOIN ".DB_PREFIX."attribute_group_description agd ON agd.attribute_group_id=ag.attribute_group_id AND agd.language_id=$languageId ";
		$sql .= "LEFT JOIN  ".DB_PREFIX."attribute a ON a.attribute_group_id=ag.attribute_group_id ";
		$sql .= "INNER JOIN  ".DB_PREFIX."attribute_description ad ON ad.attribute_id=a.attribute_id AND ad.language_id=$languageId ";
		$result = $database->query( $sql );
		foreach ($result->rows as $row) {
			$attributeGroupId = $row['attribute_group_id'];
			$attributeId = $row['attribute_id'];
			$group = $row['group'];
			$name = $row['name'];
			$attributeGroupSortOrder = $row['group_sort_order'];
			$attributeSortOrder = $row['sort_order'];
			if ($maxAttributeGroupId < $attributeGroupId) {
				$maxAttributeGroupId = $attributeGroupId;
			}
			if ($maxAttributeId < $attributeId) {
				$maxAttributeId = $attributeId;
			}
			if ($maxAttributeGroupSortOrder < $attributeGroupSortOrder) {
				$maxAttributeGroupSortOrder = $attributeGroupSortOrder;
			}
			if (!isset($maxAttributeSortOrders[$group])) {
				$maxAttributeSortOrders[$group] = $attributeSortOrder;
			}
			if ($maxAttributeSortOrders[$group] < $attributeSortOrder) {
				$maxAttributeSortOrders[$group] = $attributeSortOrder;
			}
			if (!isset($attributeGroupIds[$group])) {
				$attributeGroupIds[$group] = $attributeGroupId;
			}
			if (!isset($attributeIds[$group])) {
				$attributeIds[$group] = array();
			}
			if (!isset($attributeIds[$group][$name])) {
				$attributeIds[$group][$name] = $attributeId;
			}
		}
		
		// start transaction, remove product attributes from database
		$sql = "START TRANSACTION;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_attribute WHERE language_id=$languageId;\n";
		$this->multiquery( $database, $sql );
		
		// add product attributes to the database
		foreach ($attributes as $attribute) {
			$productId = $attribute['product_id'];
			$langId = $attribute['language_id'];
			$group = $attribute['group'];
			$name = $attribute['name'];
			$text = $attribute['text'];
			if (!isset($attributeGroupIds[$group])) {
				$maxAttributeGroupId += 1;
				$maxAttributeGroupSortOrder += 1;
				$attributeGroupId = $maxAttributeGroupId;
				$attributeGroupIds[$group] = $attributeGroupId;
				$sql  = "INSERT INTO ".DB_PREFIX."attribute_group (attribute_group_id,sort_order) VALUES ";
				$sql .= "($attributeGroupId,$maxAttributeGroupSortOrder);";
				$database->query( $sql );
				$sql  = "INSERT INTO ".DB_PREFIX."attribute_group_description (attribute_group_id,language_id,name) VALUES ";
				$sql .= "($attributeGroupId,$langId,'".$database->escape($group)."');";
				$database->query( $sql );
			}
			if (!isset($attributeIds[$group])) {
				$attributeIds[$group] = array();
			}
			if (!isset($attributeIds[$group][$name])) {
				$maxAttributeId += 1;
				$attributeId = $maxAttributeId;
				$attributeIds[$group][$name] = $attributeId;
				$attributeGroupId = $attributeGroupIds[$group];
				if (!isset($maxAttributeSortOrders[$group])) {
					$maxAttributeSortOrders[$group] = 0;
				}
				$maxAttributeSortOrders[$group] += 1;
				$attributeSortOrder = $maxAttributeSortOrders[$group];
				$sql  = "INSERT INTO ".DB_PREFIX."attribute (attribute_id,attribute_group_id,sort_order) VALUES ";
				$sql .= "($attributeId,$attributeGroupId,$attributeSortOrder);";
				$database->query( $sql );
				$sql  = "INSERT INTO ".DB_PREFIX."attribute_description (attribute_id,language_id,name) VALUES ";
				$sql .= "($attributeId,$langId,'".$database->escape($name)."');";
				$database->query( $sql );
			}
			$attributeId = $attributeIds[$group][$name];
			$sql  = "INSERT INTO ".DB_PREFIX."product_attribute (product_id,attribute_id,language_id,text) VALUES ";
			$sql .= "($productId,$attributeId,$langId,'".$database->escape( $text )."');";
			$database->query( $sql );
		}
		
		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreAttributeCells( $i, &$worksheet, $attribute ) {
		return $attribute;
	}


	function uploadAttributes( &$reader, &$database ) 
	{
		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);
		
		$data = $reader->getSheet(4);
		$attributes = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=0; $i<$k; $i+=1) {
			$j = 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$langId = $this->getCell($data,$i,$j++);
			if ($langId!=$languageId) {
				continue;
			}
			$group = trim($this->getCell($data,$i,$j++));
			if ($group=='') {
				continue;
			}
			$name = trim($this->getCell($data,$i,$j++));
			if ($name=='') {
				continue;
			}
			$text = $this->getCell($data,$i,$j++);
			$attributes[$i] = array();
			$attributes[$i]['product_id'] = $productId;
			$attributes[$i]['language_id'] = $languageId;
			$attributes[$i]['group'] = $group;
			$attributes[$i]['name'] = $name;
			$attributes[$i]['text'] = $text;
			$attributes[$i] = $this->moreAttributeCells( $i, $data, $attributes[$i] );
		}
		return $this->storeAttributesIntoDatabase( $database, $attributes );
	}


	function storeSpecialsIntoDatabase( &$database, &$specials )
	{
		$sql = "START TRANSACTION;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_special;\n";
		$this->multiquery( $database, $sql );

		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);

		// find existing customer groups from the database
		$sql = "SELECT customer_group_id, name FROM ".DB_PREFIX."customer_group_description WHERE language_id=$languageId";
		$result = $database->query( $sql );
		$maxCustomerGroupId = 0;
		$customerGroups = array();
		foreach ($result->rows as $row) {
			$customerGroupId = $row['customer_group_id'];
			$name = $row['name'];
			if (!isset($customerGroups[$name])) {
				$customerGroups[$name] = $customerGroupId;
			}
			if ($maxCustomerGroupId < $customerGroupId) {
				$maxCustomerGroupId = $customerGroupId;
			}
		}

		// add additional customer groups into the database
		foreach ($specials as $special) {
			$name = $special['customer_group'];
			if (!isset($customerGroups[$name])) {
				$maxCustomerGroupId += 1;
//				$maxCustomerGroupId += 0;
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group (customer_group_id, approval, company_id_display, company_id_required, tax_id_display, tax_id_required, sort_order) VALUES "; 
				$sql .= "($maxCustomerGroupId, 0, 1, 0, 0, 1, 1)";
				$sql .= ";\n";
				$database->query($sql);
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group_description (customer_group_id, language_id, name, description) VALUES ";
				$sql .= "($maxCustomerGroupId, $languageId, '".$database->escape($name)."', '' )"; 
				$sql .= ";\n";
				$database->query($sql);
				$customerGroups[$name] = $maxCustomerGroupId;
			}
		}

		// store product specials into the database
		$productSpecialId = 0;
		$first = TRUE;
		$sql = "INSERT INTO ".DB_PREFIX."product_special (product_special_id,product_id,customer_group_id,priority,price,date_start,date_end ) VALUES "; 
		foreach ($specials as $special) {
			$productSpecialId += 1;
			$productId = $special['product_id'];
			$name = $special['customer_group'];
//			$customerGroupId = $customerGroups[$name];
			$customerGroupId = 1;
			$priority = $special['priority'];
			$price = $special['price'];
			$dateStart = $special['date_start'];
			$dateEnd = $special['date_end'];
			$sql .= ($first) ? "\n" : ",\n";
			$first = FALSE;
//			$sql .= "($productSpecialId,$productId,$customerGroupId,$priority,$price,'$dateStart','$dateEnd')";
			$sql .= "($productSpecialId,$productId,$customerGroupId,$priority,$price, $dateStart, $dateEnd)";
		}
		if (!$first) {
			$database->query($sql);
		}

		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreSpecialCells( $i, &$j, &$worksheet, $special ) {
		return $special;
	}


	function uploadSpecials( &$reader, &$database ) 
	{
		$data = $reader->getSheet(5);
		$specials = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=0; $i<$k; $i+=1) {
			$j = 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$customerGroup = trim($this->getCell($data,$i,$j++));
			if ($customerGroup=="") {
				continue;
			}
			$priority = $this->getCell($data,$i,$j++,'0');
			$price = $this->getCell($data,$i,$j++,'0');
			$dateStart = $this->getCell($data,$i,$j++,'0000-00-00');
			$dateEnd = $this->getCell($data,$i,$j++,'0000-00-00');
			$specials[$i] = array();
			$specials[$i]['product_id'] = $productId;
			$specials[$i]['customer_group'] = $customerGroup;
			$specials[$i]['priority'] = $priority;
			$specials[$i]['price'] = $price;
			$specials[$i]['date_start'] = $dateStart;
			$specials[$i]['date_end'] = $dateEnd;
			$specials[$i] = $this->moreSpecialCells( $i, $j, $data, $specials[$i] );
		}
		return $this->storeSpecialsIntoDatabase( $database, $specials );
	}


	function storeDiscountsIntoDatabase( &$database, &$discounts )
	{
		$sql = "START TRANSACTION;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_discount;\n";
		$this->multiquery( $database, $sql );

		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);

		// find existing customer groups from the database
		$sql = "SELECT customer_group_id, name FROM ".DB_PREFIX."customer_group_description WHERE language_id=$languageId";
		$result = $database->query( $sql );
		$maxCustomerGroupId = 0;
		$customerGroups = array();
		foreach ($result->rows as $row) {
			$customerGroupId = $row['customer_group_id'];
			$name = $row['name'];
			if (!isset($customerGroups[$name])) {
				$customerGroups[$name] = $customerGroupId;
			}
			if ($maxCustomerGroupId < $customerGroupId) {
				$maxCustomerGroupId = $customerGroupId;
			}
		}

		// add additional customer groups into the database
		foreach ($discounts as $discount) {
			$name = $discount['customer_group'];
			if (!isset($customerGroups[$name])) {
				$maxCustomerGroupId += 1;
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group (customer_group_id, approval, company_id_display, company_id_required, tax_id_display, tax_id_required, sort_order) VALUES "; 
				$sql .= "($maxCustomerGroupId, 0, 1, 0, 0, 1, 1)";
				$sql .= ";\n";
				$database->query($sql);
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group_description (customer_group_id, language_id, name, description) VALUES ";
				$sql .= "($maxCustomerGroupId, $languageId, '".$database->escape($name)."', '' )"; 
				$sql .= ";\n";
				$database->query($sql);
				$customerGroups[$name] = $maxCustomerGroupId;
			}
		}

		// store product discounts into the database
		$productDiscountId = 0;
		$first = TRUE;
		$sql = "INSERT INTO ".DB_PREFIX."product_discount (product_discount_id,product_id,customer_group_id,quantity,priority,price,date_start,date_end ) VALUES "; 
		foreach ($discounts as $discount) {
			$productDiscountId += 1;
			$productId = $discount['product_id'];
			$name = $discount['customer_group'];
			$customerGroupId = $customerGroups[$name];
			$quantity = $discount['quantity'];
			$priority = $discount['priority'];
			$price = $discount['price'];
			$dateStart = $discount['date_start'];
			$dateEnd = $discount['date_end'];
			$sql .= ($first) ? "\n" : ",\n";
			$first = FALSE;
			$sql .= "($productDiscountId,$productId,$customerGroupId,$quantity,$priority,$price,'$dateStart','$dateEnd')";
		}
		if (!$first) {
			$database->query($sql);
		}

		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreDiscountCells( $i, &$j, &$worksheet, $discount ) {
		return $discount;
	}


	function uploadDiscounts( &$reader, &$database ) 
	{
		$data = $reader->getSheet(6);
		$discounts = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=0; $i<$k; $i+=1) {
			$j = 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$customerGroup = trim($this->getCell($data,$i,$j++));
			if ($customerGroup=="") {
				continue;
			}
			$quantity = $this->getCell($data,$i,$j++,'0');
			$priority = $this->getCell($data,$i,$j++,'0');
			$price = $this->getCell($data,$i,$j++,'0');
			$dateStart = $this->getCell($data,$i,$j++,'0000-00-00');
			$dateEnd = $this->getCell($data,$i,$j++,'0000-00-00');
			$discounts[$i] = array();
			$discounts[$i]['product_id'] = $productId;
			$discounts[$i]['customer_group'] = $customerGroup;
			$discounts[$i]['quantity'] = $quantity;
			$discounts[$i]['priority'] = $priority;
			$discounts[$i]['price'] = $price;
			$discounts[$i]['date_start'] = $dateStart;
			$discounts[$i]['date_end'] = $dateEnd;
			$discounts[$i] = $this->moreDiscountCells( $i, $j, $data, $discounts[$i] );
		}
		return $this->storeDiscountsIntoDatabase( $database, $discounts );
	}


	function storeRewardsIntoDatabase( &$database, &$rewards )
	{
		$sql = "START TRANSACTION;\n";
		$sql .= "DELETE FROM ".DB_PREFIX."product_reward;\n";
		$this->multiquery( $database, $sql );

		// find the default language id
		$languageId = $this->getDefaultLanguageId($database);

		// find existing customer groups from the database
		$sql = "SELECT customer_group_id, name FROM ".DB_PREFIX."customer_group_description WHERE language_id=$languageId";
		$result = $database->query( $sql );
		$maxCustomerGroupId = 0;
		$customerGroups = array();
		foreach ($result->rows as $row) {
			$customerGroupId = $row['customer_group_id'];
			$name = $row['name'];
			if (!isset($customerGroups[$name])) {
				$customerGroups[$name] = $customerGroupId;
			}
			if ($maxCustomerGroupId < $customerGroupId) {
				$maxCustomerGroupId = $customerGroupId;
			}
		}

		// add additional customer groups into the database
		foreach ($rewards as $reward) {
			$name = $reward['customer_group'];
			if (!isset($customerGroups[$name])) {
				$maxCustomerGroupId += 1;
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group (customer_group_id, approval, company_id_display, company_id_required, tax_id_display, tax_id_required, sort_order) VALUES "; 
				$sql .= "($maxCustomerGroupId, 0, 1, 0, 0, 1, 1)";
				$sql .= ";\n";
				$database->query($sql);
				$sql  = "INSERT INTO ".DB_PREFIX."customer_group_description (customer_group_id, language_id, name, description) VALUES ";
				$sql .= "($maxCustomerGroupId, $languageId, '".$database->escape($name)."', '' )"; 
				$sql .= ";\n";
				$database->query($sql);
				$customerGroups[$name] = $maxCustomerGroupId;
			}
		}

		// store product rewards into the database
		$productRewardId = 0;
		$first = TRUE;
		$sql = "INSERT INTO ".DB_PREFIX."product_reward (product_reward_id,product_id,customer_group_id,points ) VALUES "; 
		foreach ($rewards as $reward) {
			$productRewardId += 1;
			$productId = $reward['product_id'];
			$name = $reward['customer_group'];
			$customerGroupId = $customerGroups[$name];
			$points = $reward['points'];
			$sql .= ($first) ? "\n" : ",\n";
			$first = FALSE;
			$sql .= "($productRewardId,$productId,$customerGroupId,$points)";
		}
		if (!$first) {
			$database->query($sql);
		}

		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreRewardCells( $i, &$j, &$worksheet, $reward ) {
		return $reward;
	}


	function uploadRewards( &$reader, &$database ) 
	{
		$data = $reader->getSheet(7);
		$rewards = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=0; $i<$k; $i+=1) {
			$j= 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$customerGroup = trim($this->getCell($data,$i,$j++));
			if ($customerGroup=="") {
				continue;
			}
			$points = $this->getCell($data,$i,$j++,'0');
			$rewards[$i] = array();
			$rewards[$i]['product_id'] = $productId;
			$rewards[$i]['customer_group'] = $customerGroup;
			$rewards[$i]['points'] = $points;
			$rewards[$i] = $this->moreRewardCells( $i, $j, $data, $rewards[$i] );
		}
		return $this->storeRewardsIntoDatabase( $database, $rewards );
	}


	function storeAdditionalImagesIntoDatabase( &$database, &$images )
	{
		// start transaction
		$sql = "START TRANSACTION;\n";
		
		// delete old additional product images from database
		$sql = "DELETE FROM ".DB_PREFIX."product_image";
		$database->query( $sql );
		
		// store additional images into the database
		$productImageId = 0;
		$first = TRUE;
		$sql = "INSERT INTO ".DB_PREFIX."product_image (product_image_id,product_id,image,sort_order ) VALUES "; 
		foreach ($images as $image) {
			$productImageId += 1;
			$productId = $image['product_id'];
			$imageName = $image['image'];
			$sortOrder = $image['sort_order'];
			$sql .= ($first) ? "\n" : ",\n";
			$first = FALSE;
			$sql .= "($productImageId,$productId,'".$database->escape($imageName)."',$sortOrder)";
		}
		if (!$first) {
			$database->query($sql);
		}

		$database->query("COMMIT;");
		return TRUE;
	}


	// hook function for reading additional cells in class extensions
	protected function moreAdditionalImageCells( $i, &$j, &$worksheet, $image ) {
		return $image;
	}


	function uploadAdditionalImages( &$reader, &$database ) 
	{
		$data = $reader->getSheet(2);
		$images = array();
		$i = 0;
		$k = $data->getHighestRow();
		$isFirstRow = TRUE;
		for ($i=0; $i<$k; $i+=1) {
			$j= 1;
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			$productId = trim($this->getCell($data,$i,$j++));
			if ($productId=="") {
				continue;
			}
			$image = $this->getCell($data,$i,$j++,'');
			$sortOrder = $this->getCell($data,$i,$j++,'0');
			$images[$i] = array();
			$images[$i]['product_id'] = $productId;
			$images[$i]['image'] = $image;
			$images[$i]['sort_order'] = $sortOrder;
			$images[$i] = $this->moreAdditionalImageCells( $i, $j, $worksheet, $images[$i] );
		}
		return $this->storeAdditionalImagesIntoDatabase( $database, $images );
	}


	function getCell(&$worksheet,$row,$col,$default_val='') {
		$col -= 1; // we use 1-based, PHPExcel uses 0-based column index
		$row += 1; // we use 0-based, PHPExcel used 1-based row index
		return ($worksheet->cellExistsByColumnAndRow($col,$row)) ? $worksheet->getCellByColumnAndRow($col,$row)->getValue() : $default_val;
	}


	function validateHeading( &$data, &$expected ) {
		$heading = array();
		$k = PHPExcel_Cell::columnIndexFromString( $data->getHighestColumn() );
		if ($k != count($expected)) {
			return FALSE;
		}
		$i = 0;
		for ($j=1; $j <= $k; $j+=1) {
			$heading[] = $this->getCell($data,$i,$j);
		}
		$valid = TRUE;
		for ($i=0; $i < count($expected); $i+=1) {
			if (!isset($heading[$i])) {
				$valid = FALSE;
				break;
			}
			if (strtolower($heading[$i]) != strtolower($expected[$i])) {
				$valid = FALSE;
				break;
			}
		}
		return $valid;
	}

	function validateRLMHeading( &$data, &$expected ) {
		$heading = array();
		$k = PHPExcel_Cell::columnIndexFromString( $data->getHighestColumn() );

		if ($k != count($expected)) {
			return FALSE;
		}
		$i = 1;
		for ($j=1; $j <= $k; $j+=1) {
			$heading[] = $this->getCell($data,$i,$j);
		}
		$valid = TRUE;
		for ($i=0; $i < count($expected); $i+=1) {
			if (!isset($heading[$i])) {
				$valid = FALSE;
				break;
			}
			if (strtolower($heading[$i]) != strtolower($expected[$i])) {
				$valid = FALSE;
				break;
			}
		}
		return $valid;
	}

	protected function expectedCategoriesHeading() {
		return array( "category_id", "parent_id", "name", "top", "columns", "sort_order", "image_name", "date_added", "date_modified", "language_id", "seo_keyword", "description", "meta_description", "meta_keywords", "store_ids", "layout", "status\nenabled" );
	}


	function validateCategories( &$reader )
	{
		$expectedCategoriesHeading = $this->expectedCategoriesHeading();
		$data =& $reader->getSheet(0);
		return $this->validateHeading( $data, $expectedCategoriesHeading );
	}


	protected function expectedProductsHeading() {
		return array( "product_id", "name", "categories", "sku", "upc", "ean", "jan", "isbn", "mpn", "location", "quantity", "model", "manufacturer", "image_name", "requires\nshipping", "price", "points", "date_added", "date_modified", "date_available", "weight", "unit", "length", "width", "height", "length\nunit", "status\nenabled", "tax_class_id", "viewed", "language_id", "seo_keyword", "description", "meta_description", "meta_keywords", "stock_status_id", "store_ids", "layout", "related_ids", "tags", "sort_order", "subtract", "minimum" );
	}

	protected function expectedRLMProductsHeading() {
		return array( "Whse #", "Div #", "Division Name", "Sea", "Style", "Style Description", "Fabric", "", "Color", "Color Name", "F&W", "Style Status", "Size Scale", "Size", "UPC", "Group", "Category", "Sub-Div", "WS Price", "Unit Cost", "Cost Type", "Collection Desc.", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost", "Units", "WS Price", "Cost","Discount");
	}

	function validateProducts( &$reader )
	{
		$expectedProductsHeading = $this->expectedProductsHeading();
		$data =& $reader->getSheet(1);
		return $this->validateHeading( $data, $expectedProductsHeading );
	}

	function validateRLMProducts( &$reader )
	{
		$expectedProductsHeading = $this->expectedRLMProductsHeading();
		$data =& $reader->getSheet(0);
		return $this->validateRLMHeading( $data, $expectedProductsHeading );
	}

	protected function expectedAdditionalImagesHeading() {
		return array( "product_id", "image", "sort_order" );
	}


	function validateAdditionalImages( &$reader )
	{
		$expectedAdditionalImagesHeading = expectedAdditionalImagesHeading();
		$data =& $reader->getSheet(2);
		return $this->validateHeading( $data, $expectedAdditionalImagesHeading );
	}


	protected function expectedOptionsHeading() {
		return array( "product_id", "language_id", "option", "type", "value", "image", "required", "quantity", "subtract", "price", "price\nprefix", "points", "points\nprefix", "weight", "weight\nprefix", "sort_order" );
	}


	function validateOptions( &$reader )
	{
		$expectedOptionsHeading = $this->expectedOptionsHeading();
		$data =& $reader->getSheet(3);
		return $this->validateHeading( $data, $expectedOptionsHeading );
	}


	protected function expectedAttributesHeading() {
		return array( "product_id", "language_id", "attribute_group", "attribute_name", "text" );
	}


	function validateAttributes( &$reader )
	{
		$expectedAttributesHeading = $this->expectedAttributesHeading();
		$data =& $reader->getSheet(4);
		return $this->validateHeading( $data, $expectedAttributesHeading );
	}


	protected function expectedSpecialsHeading() {
		return array( "product_id", "customer_group", "priority", "price", "date_start", "date_end" );
	}


	function validateSpecials( &$reader )
	{
		$expectedSpecialsHeading = $this->expectedSpecialsHeading();
		$data =& $reader->getSheet(5);
		return $this->validateHeading( $data, $expectedSpecialsHeading );
	}


	protected function expectedDiscountsHeading() {
		return array( "product_id", "customer_group", "quantity", "priority", "price", "date_start", "date_end" );
	}


	function validateDiscounts( &$reader )
	{
		$expectedDiscountsHeading = $this->expectedDiscountsHeading();
		$data =& $reader->getSheet(6);
		return $this->validateHeading( $data, $expectedDiscountsHeading );
	}


	protected function expectedRewardsHeading() {
		return array( "product_id", "customer_group", "points" );
	}


	function validateRewards( &$reader )
	{
		$expectedRewardsHeading = $this->expectedRewardsHeading();
		$data =& $reader->getSheet(7);
		return $this->validateHeading( $data, $expectedRewardsHeading );
	}


	protected function expectedWorkSheets() {
		return array('Categories','Products','AdditionalImages','Options','Attributes','Specials','Discounts','Rewards');
	}
	
	// TODO: A Bug in the code in validateRLMupload
	function validateRLMupload( &$reader ) 
	{
		$sheetNames = $reader->getSheetNames();
		if ($sheetNames[0] != 'Cut And Sold Inquiry') {
			 error_log(date('Y-m-d H:i:s - ', time()).$this->language->get( 'error_sheet_count' )."\n",3,DIR_LOGS."error.txt");
			return FALSE;					
		}
		if (!$this->validateRLMProducts( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_products_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		return TRUE;
	}
	
	
	function validateUpload( &$reader )
	{
		$expectedWorksheets = $this->expectedWorksheets();
		$sheetNames = $reader->getSheetNames();
		foreach ($expectedWorksheets as $key=>$expectedWorksheet) {
			if (!isset($sheetNames[$key]) || ($sheetNames[$key]!=$expectedWorksheet)) {
				error_log(date('Y-m-d H:i:s - ', time()).$this->language->get( 'error_sheet_count' )."\n",3,DIR_LOGS."error.txt");
				return FALSE;
			}
		}
		if (!$this->validateCategories( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_categories_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateProducts( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_products_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateOptions( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_options_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateAttributes( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_attributes_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateSpecials( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_specials_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateDiscounts( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_discounts_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		if (!$this->validateRewards( $reader )) {
			error_log(date('Y-m-d H:i:s - ', time()).$this->language->get('error_rewards_header')."\n",3,DIR_LOGS."error.txt");
			return FALSE;
		}
		return TRUE;
	}


	function clearCache() {
		$this->cache->delete('*');
	}

	function loadEmployee($database){
		$emparray = array();
		$emplookup = array();
		$sql = "SELECT customer_id, custom_field FROM oc_customer;";
		$result = $database->query($sql);

		foreach ($result as $element) {
			foreach ($element as $row) {
				if (substr($row['custom_field'],0,4)=='PERS') {
					$emparray[$row['custom_field']]=$row['customer_id'];
				}
			}
		}
		
//		foreach ($emparray->rows as $emp) {
//			if (substr($emp['custom_field'],0,4)=='PERS') {
//				$emplookup[$emp['custom_field']]=$emp['customer_id'];
//			}
//		}
		return $emparray;
	}	

	function upload( $filename ) {
		// we use our own error handler
		global $registry;
		$registry = $this->registry;
		set_error_handler('error_handler_for_rlmexport',E_ALL);
		register_shutdown_function('fatal_error_shutdown_handler_for_rlmexport');

		try {
			$database =& $this->db;
			$this->session->data['export_nochange'] = 1;

			// we use the PHPExcel package from http://phpexcel.codeplex.com/
			$cwd = getcwd();
			chdir( DIR_SYSTEM.'PHPExcel' );
			require_once( 'Classes/PHPExcel.php' );
			chdir( $cwd );
			// parse uploaded spreadsheet file
			$inputFileType = PHPExcel_IOFactory::identify($filename);
			$objReader = PHPExcel_IOFactory::createReader($inputFileType);
			$objReader->setReadDataOnly(true);
			$reader = $objReader->load($filename);
// CHILI
			$emplist=$this->loadEmployee($database);
//var_dump($emplist);
//die();			
			// read the various worksheets and load them to the database
			$ok = $this->validateRLMUpload( $reader );
			if (!$ok) {
				return FALSE;
			}
			$this->clearCache();
			$this->session->data['export_nochange'] = 0;
			/*
			$ok = $this->uploadCategories( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			*/
			
			$ok = $this->uploadATSProducts( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			/*
			$ok = $this->uploadAdditionalImages( $reader, $database );
			if (!$ok) {
				return FALSE;
			}  */
			$ok = $this->uploadOptions( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			/*
			$ok = $this->uploadAttributes( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			$ok = $this->uploadSpecials( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			$ok = $this->uploadDiscounts( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			$ok = $this->uploadRewards( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			*/
			return $ok;
		} catch (Exception $e) {
			$errstr = $e->getMessage();
			$errline = $e->getLine();
			$errfile = $e->getFile();
			$errno = $e->getCode();
			$this->session->data['export_error'] = array( 'errstr'=>$errstr, 'errno'=>$errno, 'errfile'=>$errfile, 'errline'=>$errline );
			if ($this->config->get('config_error_log')) {
				$this->log->write('PHP ' . get_class($e) . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
			}
			return FALSE;
		}
	}



	function getStoreIdsForCategories( &$database ) {
		$sql =  "SELECT category_id, store_id FROM ".DB_PREFIX."category_to_store cs;";
		$storeIds = array();
		$result = $database->query( $sql );
		foreach ($result->rows as $row) {
			$categoryId = $row['category_id'];
			$storeId = $row['store_id'];
			if (!isset($storeIds[$categoryId])) {
				$storeIds[$categoryId] = array();
			}
			if (!in_array($storeId,$storeIds[$categoryId])) {
				$storeIds[$categoryId][] = $storeId;
			}
		}
		return $storeIds;
	}


	function getLayoutsForCategories( &$database ) {
		$sql  = "SELECT cl.*, l.name FROM ".DB_PREFIX."category_to_layout cl ";
		$sql .= "LEFT JOIN ".DB_PREFIX."layout l ON cl.layout_id = l.layout_id ";
		$sql .= "ORDER BY cl.category_id, cl.store_id;";
		$result = $database->query( $sql );
		$layouts = array();
		foreach ($result->rows as $row) {
			$categoryId = $row['category_id'];
			$storeId = $row['store_id'];
			$name = $row['name'];
			if (!isset($layouts[$categoryId])) {
				$layouts[$categoryId] = array();
			}
			$layouts[$categoryId][$storeId] = $name;
		}
		return $layouts;
	}


	protected function setCell( &$worksheet, $row/*1-based*/, $col/*0-based*/, $val, $style=NULL ) {
		if ($style) {
			$worksheet->getStyleByColumnAndRow($col,$row)->applyFromArray( $style );
			$cell = $worksheet->getCellByColumnAndRow( $col, $row );
			$style = $cell->getStyle();
			if ($style->getNumberFormat()->getFormatCode() == '@') {
				$cell->setValueExplicit( $val );
			} else {
				$cell->setValue( $val );
			}
		} else {
			$cell = $worksheet->getCellByColumnAndRow( $col, $row );
			$cell->setValue( $val );
		}
	}


	protected function getCategories( &$database, $languageId ) {
		$query  = "SELECT c.* , cd.*, ua.keyword FROM ".DB_PREFIX."category c ";
		$query .= "INNER JOIN ".DB_PREFIX."category_description cd ON cd.category_id = c.category_id ";
		$query .= " AND cd.language_id=$languageId ";
		$query .= "LEFT JOIN ".DB_PREFIX."url_alias ua ON ua.query=CONCAT('category_id=',c.category_id) ";
		$query .= "ORDER BY c.parent_id, sort_order, c.category_id;";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateCategoriesWorksheet( &$worksheet, &$database, $languageId, &$boxFormat, &$textFormat ) {
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('category_id')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('parent_id')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('name'),32)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('top'),5)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('columns')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('sort_order')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('image_name'),12)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_added'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_modified'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('language_id'),2)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('seo_keyword'),16)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('description'),32)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('meta_description'),32)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('meta_keywords'),32)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('store_ids'),16)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('layout'),16)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('status'),5)+1,$textFormat);
		
		// The heading row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'category_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'parent_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'name', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'top', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'columns', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'sort_order', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'image_name', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_added', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_modified', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'language_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'seo_keyword', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'description', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'meta_description', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'meta_keywords', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'store_ids', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'layout', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, "status\nenabled", $boxFormat );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual categories data
		$i += 1;
		$j = 0;
		$storeIds = $this->getStoreIdsForCategories( $database );
		$layouts = $this->getLayoutsForCategories( $database );
		$categories = $this->getCategories( $database, $languageId );
		foreach ($categories as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(26);
			$this->setCell( $worksheet, $i, $j++, $row['category_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['parent_id'] );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['name'],ENT_QUOTES,'UTF-8') );
			$this->setCell( $worksheet, $i, $j++, ($row['top']==0) ? "false" : "true", $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['column'] );
			$this->setCell( $worksheet, $i, $j++, $row['sort_order'] );
			$this->setCell( $worksheet, $i, $j++, $row['image'] );
			$this->setCell( $worksheet, $i, $j++, $row['date_added'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_modified'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['language_id'] );
			$this->setCell( $worksheet, $i, $j++, ($row['keyword']) ? $row['keyword'] : '' );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['description'],ENT_QUOTES,'UTF-8') );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['meta_description'],ENT_QUOTES,'UTF-8') );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['meta_keyword'],ENT_QUOTES,'UTF-8') );
			$storeIdList = '';
			$categoryId = $row['category_id'];
			if (isset($storeIds[$categoryId])) {
				foreach ($storeIds[$categoryId] as $storeId) {
					$storeIdList .= ($storeIdList=='') ? $storeId : ','.$storeId;
				}
			}
			$this->setCell( $worksheet, $i, $j++, $storeIdList, $textFormat );
			$layoutList = '';
			if (isset($layouts[$categoryId])) {
				foreach ($layouts[$categoryId] as $storeId => $name) {
					$layoutList .= ($layoutList=='') ? $storeId.':'.$name : ','.$storeId.':'.$name;
				}
			}
			$this->setCell( $worksheet, $i, $j++, $layoutList, $textFormat );
			$this->setCell( $worksheet, $i, $j++, ($row['status']==0) ? "false" : "true", $textFormat );
			$i += 1;
			$j = 0;
		}
	}


	protected function getStoreIdsForProducts( &$database ) {
		$sql =  "SELECT product_id, store_id FROM ".DB_PREFIX."product_to_store ps;";
		$storeIds = array();
		$result = $database->query( $sql );
		foreach ($result->rows as $row) {
			$productId = $row['product_id'];
			$storeId = $row['store_id'];
			if (!isset($storeIds[$productId])) {
				$storeIds[$productId] = array();
			}
			if (!in_array($storeId,$storeIds[$productId])) {
				$storeIds[$productId][] = $storeId;
			}
		}
		return $storeIds;
	}


	protected function getLayoutsForProducts( &$database ) {
		$sql  = "SELECT pl.*, l.name FROM ".DB_PREFIX."product_to_layout pl ";
		$sql .= "LEFT JOIN ".DB_PREFIX."layout l ON pl.layout_id = l.layout_id ";
		$sql .= "ORDER BY pl.product_id, pl.store_id;";
		$result = $database->query( $sql );
		$layouts = array();
		foreach ($result->rows as $row) {
			$productId = $row['product_id'];
			$storeId = $row['store_id'];
			$name = $row['name'];
			if (!isset($layouts[$productId])) {
				$layouts[$productId] = array();
			}
			$layouts[$productId][$storeId] = $name;
		}
		return $layouts;
	}


	protected function getProducts( &$database, $languageId ) {
		$query  = "SELECT ";
		$query .= "  p.product_id,";
		$query .= "  pd.name,";
		$query .= "  GROUP_CONCAT( DISTINCT CAST(pc.category_id AS CHAR(11)) SEPARATOR \",\" ) AS categories,";
		$query .= "  p.sku,";
		$query .= "  p.upc,";
		$query .= "  p.ean,";
		$query .= "  p.jan,";
		$query .= "  p.isbn,";
		$query .= "  p.mpn,";
		$query .= "  p.location,";
		$query .= "  p.quantity,";
		$query .= "  p.model,";
		$query .= "  m.name AS manufacturer,";
		$query .= "  p.image AS image_name,";
		$query .= "  p.shipping,";
		$query .= "  p.price,";
		$query .= "  p.points,";
		$query .= "  p.date_added,";
		$query .= "  p.date_modified,";
		$query .= "  p.date_available,";
		$query .= "  p.weight,";
		$query .= "  wc.unit,";
		$query .= "  p.length,";
		$query .= "  p.width,";
		$query .= "  p.height,";
		$query .= "  p.status,";
		$query .= "  p.tax_class_id,";
		$query .= "  p.viewed,";
		$query .= "  p.sort_order,";
		$query .= "  pd.language_id,";
		$query .= "  ua.keyword,";
		$query .= "  pd.description, ";
		$query .= "  pd.meta_description, ";
		$query .= "  pd.meta_keyword, ";
		$query .= "  pd.tag, ";
		$query .= "  p.stock_status_id, ";
		$query .= "  mc.unit AS length_unit, ";
		$query .= "  p.subtract, ";
		$query .= "  p.minimum, ";
		$query .= "  GROUP_CONCAT( DISTINCT CAST(pr.related_id AS CHAR(11)) SEPARATOR \",\" ) AS related ";
		$query .= "FROM ".DB_PREFIX."product p ";
		$query .= "LEFT JOIN ".DB_PREFIX."product_description pd ON p.product_id=pd.product_id ";
		$query .= "  AND pd.language_id=$languageId ";
		$query .= "LEFT JOIN ".DB_PREFIX."product_to_category pc ON p.product_id=pc.product_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."url_alias ua ON ua.query=CONCAT('product_id=',p.product_id) ";
		$query .= "LEFT JOIN ".DB_PREFIX."manufacturer m ON m.manufacturer_id = p.manufacturer_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."weight_class_description wc ON wc.weight_class_id = p.weight_class_id ";
		$query .= "  AND wc.language_id=$languageId ";
		$query .= "LEFT JOIN ".DB_PREFIX."length_class_description mc ON mc.length_class_id=p.length_class_id ";
		$query .= "  AND mc.language_id=$languageId ";
		$query .= "LEFT JOIN ".DB_PREFIX."product_related pr ON pr.product_id=p.product_id ";
		$query .= "GROUP BY p.product_id ";
		$query .= "ORDER BY p.product_id, pc.category_id; ";
		$result = $database->query( $query );
		return $result->rows;
	}


	function populateProductsWorksheet( &$worksheet, &$database, $languageId, &$priceFormat, &$boxFormat, &$weightFormat, &$textFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('product_id'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('name'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('categories'),12)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('sku'),10)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('upc'),12)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('ean'),14)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('jan'),13)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('isbn'),13)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('mpn'),15)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('location'),10)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('quantity'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('model'),8)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('manufacturer'),10)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('image_name'),12)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('shipping'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('price'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('points'),5)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_added'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_modified'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_available'),10)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('weight'),6)+1,$weightFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('unit'),3)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('length'),8)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('width'),8)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('height'),8)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('length'),3)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('status'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('tax_class_id'),2)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('viewed'),5)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('language_id'),2)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('seo_keyword'),16)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('description'),32)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('meta_description'),32)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('meta_keywords'),32)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('stock_status_id'),3)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('store_ids'),16)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('layout'),16)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('related_ids'),16)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('tags'),32)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('sort_order'),8)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('subtract'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('minimum'),8)+1);

		// The product headings row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'name', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'categories', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'sku', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'upc', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'ean', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'jan', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'isbn', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'mpn', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'location', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'quantity', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'model', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'manufacturer', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'image_name', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, "requires\nshipping", $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'price', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'points', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_added', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_modified', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_available', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'weight', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'unit', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'length', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'width', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'height', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, "length\nunit", $boxFormat );
		$this->setCell( $worksheet, $i, $j++, "status\nenabled", $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'tax_class_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'viewed', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'language_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'seo_keyword', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'description', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'meta_description', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'meta_keywords', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'stock_status_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'store_ids', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'layout', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'related_ids', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'tags', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'sort_order', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, "subtract", $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'minimum', $boxFormat );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual products data
		$i += 1;
		$j = 0;
		$storeIds = $this->getStoreIdsForProducts( $database );
		$layouts = $this->getLayoutsForProducts( $database );
		$products = $this->getProducts( $database, $languageId );
		foreach ($products as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(26);
			$productId = $row['product_id'];
			$this->setCell( $worksheet, $i, $j++, $productId );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['name'],ENT_QUOTES,'UTF-8') );
			$this->setCell( $worksheet, $i, $j++, $row['categories'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['sku'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['upc'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['ean'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['jan'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['isbn'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['mpn'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['location'] );
			$this->setCell( $worksheet, $i, $j++, $row['quantity'] );
			$this->setCell( $worksheet, $i, $j++, $row['model'] );
			$this->setCell( $worksheet, $i, $j++, $row['manufacturer'] );
			$this->setCell( $worksheet, $i, $j++, $row['image_name'] );
			$this->setCell( $worksheet, $i, $j++, ($row['shipping']==0) ? "no" : "yes", $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['price'], $priceFormat );
			$this->setCell( $worksheet, $i, $j++, $row['points'] );
			$this->setCell( $worksheet, $i, $j++, $row['date_added'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_modified'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_available'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['weight'], $weightFormat );
			$this->setCell( $worksheet, $i, $j++, $row['unit'] );
			$this->setCell( $worksheet, $i, $j++, $row['length'] );
			$this->setCell( $worksheet, $i, $j++, $row['width'] );
			$this->setCell( $worksheet, $i, $j++, $row['height'] );
			$this->setCell( $worksheet, $i, $j++, $row['length_unit'] );
			$this->setCell( $worksheet, $i, $j++, ($row['status']==0) ? "false" : "true", $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['tax_class_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['viewed'] );
			$this->setCell( $worksheet, $i, $j++, $row['language_id'] );
			$this->setCell( $worksheet, $i, $j++, ($row['keyword']) ? $row['keyword'] : '' );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['description'],ENT_QUOTES,'UTF-8'), $textFormat, TRUE );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['meta_description'],ENT_QUOTES,'UTF-8'), $textFormat );
			$this->setCell( $worksheet, $i, $j++, html_entity_decode($row['meta_keyword'],ENT_QUOTES,'UTF-8'), $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['stock_status_id'] );
			$storeIdList = '';
			if (isset($storeIds[$productId])) {
				foreach ($storeIds[$productId] as $storeId) {
					$storeIdList .= ($storeIdList=='') ? $storeId : ','.$storeId;
				}
			}
			$this->setCell( $worksheet, $i, $j++, $storeIdList, $textFormat );
			$layoutList = '';
			if (isset($layouts[$productId])) {
				foreach ($layouts[$productId] as $storeId => $name) {
					$layoutList .= ($layoutList=='') ? $storeId.':'.$name : ','.$storeId.':'.$name;
				}
			}
			$this->setCell( $worksheet, $i, $j++, $layoutList, $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['related'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, ($row['tag']) ? $row['tag'] : '' );
			$this->setCell( $worksheet, $i, $j++, $row['sort_order'] );
			$this->setCell( $worksheet, $i, $j++, ($row['subtract']==0) ? "false" : "true", $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['minimum'] );
			$i += 1;
			$j = 0;
		}
	}


	protected function getAdditionalImages( &$database ) {
		$query  = "SELECT product_id, image, sort_order ";
		$query .= "FROM ".DB_PREFIX."product_image pi ";
		$query .= "ORDER BY pi.product_id, pi.sort_order, pi.image;";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateAdditionalImagesWorksheet( &$worksheet, &$database, &$boxFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('product_id'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('image'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('sort_order'),5)+1);
		
		// The additional images headings row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'image', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'sort_order', $boxFormat  );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual additional images data
		$i += 1;
		$j = 0;
		$addtionalImages = $this->getAdditionalImages( $database );
		foreach ($addtionalImages as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['image'] );
			$this->setCell( $worksheet, $i, $j++, $row['sort_order'] );
			$i += 1;
			$j = 0;
		}
	}


	protected function getOptions( &$database, $languageId ) {
		$query  = "SELECT po.product_id,";
		$query .= "  po.option_id,";
		$query .= "  po.option_value AS default_value,";
		$query .= "  po.required,";
		$query .= "  pov.option_value_id,";
		$query .= "  pov.quantity,";
		$query .= "  pov.subtract,";
		$query .= "  pov.price,";
		$query .= "  pov.price_prefix,";
		$query .= "  pov.points,";
		$query .= "  pov.points_prefix,";
		$query .= "  pov.weight,";
		$query .= "  pov.weight_prefix,";
		$query .= "  ovd.name AS option_value,";
		$query .= "  ov.image,";
		$query .= "  ov.sort_order,";
		$query .= "  od.name AS option_name,";
		$query .= "  o.type ";
		$query .= "FROM ".DB_PREFIX."product_option po ";
		$query .= "LEFT JOIN ".DB_PREFIX."option o ON o.option_id=po.option_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."product_option_value pov ON pov.product_option_id = po.product_option_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."option_value ov ON ov.option_value_id=pov.option_value_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."option_value_description ovd ON ovd.option_value_id=ov.option_value_id AND ovd.language_id=$languageId ";
		$query .= "LEFT JOIN ".DB_PREFIX."option_description od ON od.option_id=o.option_id AND od.language_id=$languageId ";
		$query .= "ORDER BY po.product_id, po.option_id, pov.option_value_id;";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateOptionsWorksheet( &$worksheet, &$database, $languageId, &$priceFormat, &$boxFormat, &$weightFormat, $textFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('product_id'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('language_id'),2)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('option'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('type'),10)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('value'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('image'),12)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('required'),5)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('quantity'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('subtract'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('price'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('price'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('points'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('points'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('weight'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('weight'),5)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('sort_order'),5)+1);
		
		// The options headings row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'language_id', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'option', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'type', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'value', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'image', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'required', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'quantity', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'subtract', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'price', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, "price\nprefix", $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'points', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, "points\nprefix", $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'weight', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, "weight\nprefix", $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'sort_order', $boxFormat  );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual options data
		$i += 1;
		$j = 0;
		$options = $this->getOptions( $database, $languageId );
		foreach ($options as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $languageId );
			$this->setCell( $worksheet, $i, $j++, $row['option_name'] );
			$this->setCell( $worksheet, $i, $j++, $row['type'] );
			$this->setCell( $worksheet, $i, $j++, ($row['default_value']) ? $row['default_value'] : $row['option_value'] );
			$this->setCell( $worksheet, $i, $j++, $row['image'] );
			$this->setCell( $worksheet, $i, $j++, ($row['required']==0) ? "false" : "true", $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['quantity'] );
			if (is_null($row['option_value_id'])) {
				$subtract = '';
			} else {
				$subtract = ($row['subtract']==0) ? "false" : "true";
			}
			$this->setCell( $worksheet, $i, $j++, $subtract, $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['price'], $priceFormat );
			$this->setCell( $worksheet, $i, $j++, $row['price_prefix'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['points'] );
			$this->setCell( $worksheet, $i, $j++, $row['points_prefix'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['weight'], $weightFormat );
			$this->setCell( $worksheet, $i, $j++, $row['weight_prefix'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['sort_order'] );
			$i += 1;
			$j = 0;
		}
	}


	protected function getAttributes( &$database, $languageId ) {
		$query  = "SELECT pa.*, ag.attribute_group_id, ag.sort_order AS attribute_group_sort_order, agd.name AS attribute_group, a.attribute_id, a.sort_order, ad.name AS attribute_name ";
		$query .= "FROM ".DB_PREFIX."product_attribute pa ";
		$query .= "LEFT JOIN ".DB_PREFIX."attribute a ON a.attribute_id=pa.attribute_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."attribute_description ad ON ad.attribute_id=a.attribute_id AND ad.language_id=$languageId ";
		$query .= "INNER JOIN ".DB_PREFIX."attribute_group ag ON ag.attribute_group_id=a.attribute_group_id ";
		$query .= "LEFT JOIN ".DB_PREFIX."attribute_group_description agd ON agd.attribute_group_id=a.attribute_group_id AND agd.language_id=$languageId ";
		$query .= "WHERE pa.language_id=$languageId ";
		$query .= "ORDER BY pa.product_id, attribute_group_sort_order, ag.attribute_group_id, a.sort_order, a.attribute_id;";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateAttributesWorksheet( &$worksheet, &$database, $languageId, &$boxFormat, $textFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('product_id'),4)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('language_id'),2)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('attribute_group'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('attribute_name'),30)+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('text'),30)+1);
		
		// The attributes headings row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'language_id', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'attribute_group', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'attribute_name', $boxFormat  );
		$this->setCell( $worksheet, $i, $j++, 'text', $boxFormat  );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual attributes data
		$i += 1;
		$j = 0;
		$attributes = $this->getAttributes( $database, $languageId );
		foreach ($attributes as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $languageId );
			$this->setCell( $worksheet, $i, $j++, $row['attribute_group'] );
			$this->setCell( $worksheet, $i, $j++, $row['attribute_name'] );
			$this->setCell( $worksheet, $i, $j++, $row['text'] );
			$i += 1;
			$j = 0;
		}
	}


	protected function getSpecials( &$database, $languageId ) {
		$query  = "SELECT ps.*, cgd.name FROM ".DB_PREFIX."product_special ps ";
		$query .= "LEFT JOIN ".DB_PREFIX."customer_group_description cgd ON cgd.customer_group_id=ps.customer_group_id ";
		$query .= "  AND cgd.language_id=$languageId ";
		$query .= "ORDER BY ps.product_id, cgd.name";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateSpecialsWorksheet( &$worksheet, &$database, $languageId, &$priceFormat, &$boxFormat, &$textFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('product_id')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('customer_group')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('priority')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('price'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_start'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_end'),19)+1,$textFormat);
		
		// The heading row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'customer_group', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'priority', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'price', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_start', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_end', $boxFormat );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual product specials data
		$i += 1;
		$j = 0;
		$specials = $this->getSpecials( $database, $languageId );
		foreach ($specials as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['name'] );
			$this->setCell( $worksheet, $i, $j++, $row['priority'] );
			$this->setCell( $worksheet, $i, $j++, $row['price'], $priceFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_start'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_end'], $textFormat );
			$i += 1;
			$j = 0;
		}
	}


	protected function getDiscounts( &$database, $languageId ) {
		$query  = "SELECT pd.*, cgd.name FROM ".DB_PREFIX."product_discount pd ";
		$query .= "LEFT JOIN ".DB_PREFIX."customer_group_description cgd ON cgd.customer_group_id=pd.customer_group_id ";
		$query .= "  AND cgd.language_id=$languageId ";
		$query .= "ORDER BY pd.product_id, cgd.name";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateDiscountsWorksheet( &$worksheet, &$database, $languageId, &$priceFormat, &$boxFormat, &$textFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('product_id')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('customer_group')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('quantity')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('priority')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('price'),10)+1,$priceFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_start'),19)+1,$textFormat);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(max(strlen('date_end'),19)+1,$textFormat);
		
		// The heading row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'customer_group', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'quantity', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'priority', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'price', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_start', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'date_end', $boxFormat );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual product discounts data
		$i += 1;
		$j = 0;
		$discounts = $this->getDiscounts( $database, $languageId );
		foreach ($discounts as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['name'] );
			$this->setCell( $worksheet, $i, $j++, $row['quantity'] );
			$this->setCell( $worksheet, $i, $j++, $row['priority'] );
			$this->setCell( $worksheet, $i, $j++, $row['price'], $priceFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_start'], $textFormat );
			$this->setCell( $worksheet, $i, $j++, $row['date_end'], $textFormat );
			$i += 1;
			$j = 0;
		}
	}


	protected function getRewards( &$database, $languageId ) {
		$query  = "SELECT pr.*, cgd.name FROM ".DB_PREFIX."product_reward pr ";
		$query .= "LEFT JOIN ".DB_PREFIX."customer_group_description cgd ON cgd.customer_group_id=pr.customer_group_id ";
		$query .= "  AND cgd.language_id=$languageId ";
		$query .= "ORDER BY pr.product_id, cgd.name";
		$result = $database->query( $query );
		return $result->rows;
	}


	protected function populateRewardsWorksheet( &$worksheet, &$database, $languageId, &$boxFormat )
	{
		// Set the column widths
		$j = 0;
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('product_id')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('customer_group')+1);
		$worksheet->getColumnDimensionByColumn($j++)->setWidth(strlen('points')+1);
		
		// The heading row
		$i = 1;
		$j = 0;
		$this->setCell( $worksheet, $i, $j++, 'product_id', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'customer_group', $boxFormat );
		$this->setCell( $worksheet, $i, $j++, 'points', $boxFormat );
		$worksheet->getRowDimension($i)->setRowHeight(30);
		
		// The actual product rewards data
		$i += 1;
		$j = 0;
		$rewards = $this->getRewards( $database, $languageId );
		foreach ($rewards as $row) {
			$worksheet->getRowDimension($i)->setRowHeight(13);
			$this->setCell( $worksheet, $i, $j++, $row['product_id'] );
			$this->setCell( $worksheet, $i, $j++, $row['name'] );
			$this->setCell( $worksheet, $i, $j++, $row['points'] );
			$i += 1;
			$j = 0;
		}
	}


	protected function clearSpreadsheetCache() {
		$files = glob(DIR_CACHE . 'Spreadsheet_Excel_Writer' . '*');
		
		if ($files) {
			foreach ($files as $file) {
				if (file_exists($file)) {
					@unlink($file);
					clearstatcache();
				}
			}
		}
	}


	function download() {
		// we use our own error handler
		global $registry;
		$registry = $this->registry;
		set_error_handler('error_handler_for_export',E_ALL);
		register_shutdown_function('fatal_error_shutdown_handler_for_export');

		// we use the PHPExcel package from http://phpexcel.codeplex.com/
		$cwd = getcwd();
		chdir( DIR_SYSTEM.'PHPExcel' );
		require_once( 'Classes/PHPExcel.php' );
		chdir( $cwd );

		try {
			// set appropriate timeout limit
			set_time_limit( 1800 );

			$database =& $this->db;
			$languageId = $this->getDefaultLanguageId($database);

			// create a new workbook
			$workbook = new PHPExcel();

			// set default font name and size
			$workbook->getDefaultStyle()->getFont()->setName('Arial');
			$workbook->getDefaultStyle()->getFont()->setSize(10);
			$workbook->getDefaultStyle()->getAlignment()->setIndent(1);

			// pre-define some commonly used styles
			$boxFormat = array(
				'font' => array(
					'name' => 'Arial',
					'size' => '10',
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
					'vertical'   => PHPExcel_Style_Alignment::VERTICAL_CENTER,
					'wrap'       => false,
					'indent'     => 0
				)
			);
			$textFormat = array(
				'font' => array(
					'name' => 'Arial',
					'size' => '10',
				),
				'numberformat' => array(
					'code' => PHPExcel_Style_NumberFormat::FORMAT_TEXT
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
					'vertical'   => PHPExcel_Style_Alignment::VERTICAL_CENTER,
					'wrap'       => false,
					'indent'     => 0
				)
			);
			$priceFormat = array(
				'font' => array(
					'name' => 'Arial',
					'size' => '10',
				),
				'numberformat' => array(
					'code' => '######0.00'
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_RIGHT,
					'vertical'   => PHPExcel_Style_Alignment::VERTICAL_CENTER,
					'wrap'       => false,
					'indent'     => 0
				)
			);
			$weightFormat = array(
				'font' => array(
					'name' => 'Arial',
					'size' => '10',
				),
				'numberformat' => array(
					'code' => '##0.00'
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_RIGHT,
					'vertical'   => PHPExcel_Style_Alignment::VERTICAL_CENTER,
					'wrap'       => false,
					'indent'     => 0
				)
			);

			// creating the Categories worksheet
			$workbook->setActiveSheetIndex(0);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Categories' );
			$this->populateCategoriesWorksheet( $worksheet, $database, $languageId, $boxFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Products worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(1);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Products' );
			$this->populateProductsWorksheet( $worksheet, $database, $languageId, $priceFormat, $boxFormat, $weightFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the AdditionalImages worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(2);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'AdditionalImages' );
			$this->populateAdditionalImagesWorksheet( $worksheet, $database, $boxFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Options worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(3);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Options' );
			$this->populateOptionsWorksheet( $worksheet, $database, $languageId, $priceFormat, $boxFormat, $weightFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Attributes worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(4);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Attributes' );
			$this->populateAttributesWorksheet( $worksheet, $database, $languageId, $boxFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Specials worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(5);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Specials' );
			$this->populateSpecialsWorksheet( $worksheet, $database, $languageId, $priceFormat, $boxFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Discounts worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(6);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Discounts' );
			$this->populateDiscountsWorksheet( $worksheet, $database, $languageId, $priceFormat, $boxFormat, $textFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			// creating the Rewards worksheet
			$workbook->createSheet();
			$workbook->setActiveSheetIndex(7);
			$worksheet = $workbook->getActiveSheet();
			$worksheet->setTitle( 'Rewards' );
			$this->populateRewardsWorksheet( $worksheet, $database, $languageId, $boxFormat );
			$worksheet->freezePaneByColumnAndRow( 1, 2 );

			$workbook->setActiveSheetIndex(0);

			// redirect output to client browser
			header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header('Content-Disposition: attachment;filename="backup_categories_products.xlsx"');
			header('Cache-Control: max-age=0');
			$objWriter = PHPExcel_IOFactory::createWriter($workbook, 'Excel2007');
			$objWriter->save('php://output');

			// Clear the spreadsheet caches
			$this->clearSpreadsheetCache();
			exit;

		} catch (Exception $e) {
			$errstr = $e->getMessage();
			$errline = $e->getLine();
			$errfile = $e->getFile();
			$errno = $e->getCode();
			$this->session->data['export_error'] = array( 'errstr'=>$errstr, 'errno'=>$errno, 'errfile'=>$errfile, 'errline'=>$errline );
			if ($this->config->get('config_error_log')) {
				$this->log->write('PHP ' . get_class($e) . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
			}
			return;
		}
	}
/***** BEGIN OF EMPLOYEE MODULE ********/
	function employee( $filename ) {
		// we use our own error handler
		global $registry;
		$registry = $this->registry;
		set_error_handler('error_handler_for_rlmexport',E_ALL);
		register_shutdown_function('fatal_error_shutdown_handler_for_rlmexport');

		try {
			$database =& $this->db;
			$this->session->data['export_nochange'] = 1;

			// we use the PHPExcel package from http://phpexcel.codeplex.com/
			$cwd = getcwd();
			chdir( DIR_SYSTEM.'PHPExcel' );
			require_once( 'Classes/PHPExcel.php' );
			chdir( $cwd );
			// parse uploaded spreadsheet file
			$inputFileType = PHPExcel_IOFactory::identify($filename);
			$objReader = PHPExcel_IOFactory::createReader($inputFileType);
			$objReader->setReadDataOnly(true);
			$reader = $objReader->load($filename);
			
			// read the various worksheets and load them to the database
			$ok = $this->validateEmployee( $reader );
			if (!$ok) {
				return FALSE;
			}
			$this->clearCache();
			$this->session->data['export_nochange'] = 0;
			
			$ok = $this->uploadEmployee( $reader, $database );
			if (!$ok) {
				return FALSE;
			}
			return $ok;
			
		} catch (Exception $e) {
			$errstr = $e->getMessage();
			$errline = $e->getLine();
			$errfile = $e->getFile();
			$errno = $e->getCode();
			$this->session->data['export_error'] = array( 'errstr'=>$errstr, 'errno'=>$errno, 'errfile'=>$errfile, 'errline'=>$errline );
			if ($this->config->get('config_error_log')) {
				$this->log->write('PHP ' . get_class($e) . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
			}
			return FALSE;
		}
	}

	function validateEmployee( &$reader ) 
	{
		$sheetNames = $reader->getSheetNames();
		if ($sheetNames[0] != 'AR Customer Listing') {
			 error_log(date('Y-m-d H:i:s - ', time()).$this->language->get( 'error_sheet_count' )."\n",3,DIR_LOGS."error.txt");
			return FALSE;					
		}
		return TRUE;
	}

	function uploadEmployee( &$reader, &$database ) {

		$data = $reader->getSheet(0);
		$employees = array();
		$employee = array();
		$name = array();
		$empcol = $this->empcol();
		$isFirstRow = TRUE;
		$isHeaderRow = TRUE;
		$i = 0;
		$k = $data->getHighestRow();
		for ($i=0; $i<$k; $i+=1) {
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			if ($isHeaderRow) {
				$isHeaderRow = FALSE;
				continue;
			}								
			$employees[$i]['customer_id']= 'NULL';
			$employees[$i]['customer_group_id']=1;
			$employees[$i]['store_id']=0;
			$fullname = $this->getCell($data, $i, $empcol['CustName']);
			$name = explode(" ", $fullname,2);
			$employees[$i]['firstname']=htmlentities( $name[0], ENT_QUOTES, $this->detect_encoding($name[0]) );
			$employees[$i]['lastname']=htmlentities( $name[1], ENT_QUOTES, $this->detect_encoding($name[1]) );;
//			$employees[$i]['email']=strtolower($this->getCell($data, $i, $empcol['E-mail']));
			$employees[$i]['email']=strtolower($employees[$i]['firstname'].".".$employees[$i]['lastname']."@alexanderwang.com");
			$employees[$i]['telephone']='212-532-3103';
			$employees[$i]['fax']='212-532-3110';
			$employees[$i]['password']='a85288724ea4fed586f7b681c4308cfe84cf384a';   //employeestore
			$employees[$i]['salt']='t23BUUBbv';
			$employees[$i]['cart']='';
			$employees[$i]['wishlist']='';
			$employees[$i]['newsletter']=0;
			$employees[$i]['address_id']='LAST_INSERT_ID()+1';
			$employees[$i]['custom_field']=$this->getCell($data, $i, $empcol['PostTo']);
			$employees[$i]['ip']='';
			$employees[$i]['status']=1;
			$employees[$i]['approved']=1;
			$employees[$i]['safe']=0;
			$employees[$i]['token']='';
			$employees[$i]['date_added']='NOW()';
			$employees[$i]['allowance']=$this->getCell($data, $i, $empcol['ALLOW.BAL']);
			$employees[$i]['hold']=$this->getCell($data, $i, $empcol['HOLD']);
		}
		return $this->storeEmployee( $database, $employees );
	}
	
	function empcol(){
		return array ('Cust'=>1,'PostTo'=>2,'CustName'=>3,'AddrLine1'=>4,'AddrLine2'=>5,'AddrLine3'=>6,'State'=>7,'PostalCode'=>8,'E-mail'=>34,'ALLOW.BAL'=>37,'HOLD'=>38);
	}	
	
	function storeEmployee( &$database, &$employees ) 
	{
		$colcheck = array();
		$sql = "SELECT allowance,hold FROM oc_customer;";
		$colcheck = $database->query($sql);
		$sql = "";
		if (!$colcheck) {
			$sql = "ALTER TABLE oc_customer ADD allowance DECIMAL(15,4);\n";
			$sql .="ALTER TABLE oc_customer ADD hold VARCHAR(32);";
			$this->multiquery( $database, $sql );
			$sql="";
		}
		// start transaction, 
		$sql = "START TRANSACTION;\n";
		// disable all accounts
//		$sql .= "USE eshop;\n";   //** PROBLEM: The database name should not be hardcoded!!!
		$sql .= "SET SQL_SAFE_UPDATES=0;\n";
//		$sql .= "ALTER TABLE oc_customer MODIFY custom_field varchar(32);\n";
//		$sql .= "ALTER TABLE oc_customer ADD UNIQUE (custom_field);\n";
		$sql .= "UPDATE `".DB_PREFIX."customer` SET status=0;\n";
		$this->multiquery( $database, $sql );
		$sql='';
		$database->query($sql);
		$database->query("COMMIT;");
		//keep track of new products and updated ones
		$newones = 0;
		$updateemp = 0;
		$firstone = $database->getLastId();

		// generate and execute SQL for storing the employee allowance balances
		//		seaweed
		$result = array();
		$query = "SELECT b.custom_field, c.customer_id, SUM(c.amount) Allowance FROM oc_customer_transaction c LEFT JOIN oc_customer b ON c.customer_id=b.customer_id GROUP BY c.customer_id;";
		$result = $database->query($query);
		$allow_lookup = array();
		foreach ($result as $element) {
			foreach ($element as $row) {
				$allow_lookup[$row["custom_field"]] = $row["Allowance"];
			}
		}
		foreach ($employees as $employee) {
			$customer_id = $employee['customer_id'];
			$customer_group_id = $employee['customer_group_id'];
			$store_id = $employee['store_id'];
			$firstname = "'".$employee['firstname']."'";
			$lastname = "'".$employee['lastname']."'";
			$email = "'".$employee['email']."'";
			$telephone = "'".$database->escape($employee['telephone'])."'";
			$fax = "'".$database->escape($employee['fax'])."'";
			$password = "'".$database->escape($employee['password'])."'";
			$salt = "'".$database->escape($employee['salt'])."'";
			$cart = "'".$employee['cart']."'";
			$wishlist = "'".$employee['wishlist']."'";
			$newsletter = $employee['newsletter'];
			$address_id = $employee['address_id'];
			$custom_field = "'".$database->escape($employee['custom_field'])."'";
			$ip = "'".$employee['ip']."'";
			$status = $employee['status'];
			$approved = $employee['approved'];
			$safe = $employee['safe'];
			$token = "'".$employee['token']."'";
			$date_added = $database->escape($employee['date_added']);
			$allowance = "'".$database->escape($employee['allowance'])."'";
			$hold = "'".$employee['hold']."'";

			$lastonestart = $database->getLastId();
			$sql = '';
			$sql  = "INSERT INTO ".DB_PREFIX."customer (customer_id,customer_group_id,store_id,firstname,lastname,email,telephone,fax,password,salt,cart,wishlist,newsletter,address_id,custom_field,ip,status,approved,safe,token,date_added,allowance,hold)";
			$sql .= " VALUES ";
			$sql .= "($customer_id,$customer_group_id,$store_id,$firstname,$lastname,$email,$telephone,$fax,$password,$salt,$cart,$wishlist,$newsletter,$address_id,$custom_field,$ip,$status,$approved,$safe,$token,$date_added,$allowance,$hold)";
			$sql .= " ON DUPLICATE KEY UPDATE ";
			$sql .= "status=VALUES(status), allowance=VALUES(allowance), hold=VALUES(hold);";
// echo $sql."<br>";
// 			$this->db->query($sql);
 			$database->query($sql);
//			$tesql = '';
//			$tesql = "SELECT * FROM oc_customer WHERE custom_field=$custom_field;";
// 			$result = $this->db->query($tesql);
//var_dump($result);
			$lastoneend = $database->getLastId();
//die("[$lastoneend]");
			$updateemp++;
//			echo "Start[$lastonestart] End[$lastoneend] <br>";
//			if ($lastoneend > $lastonestart){
				$sql2 ='';
				$sql2 = "INSERT INTO ".DB_PREFIX."address (address_id,customer_id,firstname,lastname,company,address_1,address_2,city,postcode,country_id,zone_id,custom_field) VALUES ";
				$sql2 .= "($lastoneend,$lastoneend,$firstname,$lastname,'Alexander Wang, Inc.','386 Broadway','Ground Floor','New York','10013','223','3655',$custom_field)";
				$sql2 .= " ON DUPLICATE KEY UPDATE ";
				$sql2 .= "firstname=VALUES(firstname),lastname=VALUES(lastname),company=VALUES(company),address_1=VALUES(address_1),address_2=VALUES(address_2),city=VALUES(city),postcode=VALUES(postcode),country_id=VALUES(country_id),zone_id=VALUES(zone_id),custom_field=VALUES(custom_field);";
	// echo $sql2."  hh<br>";
				$database->query($sql2);
//			}
//	echo "KEK *******".$updateemp."****************"."<br>";					
//	echo "Comparing *******".str_replace("'","",$custom_field)."****************"."<br>";					
//	echo "Comparing *******".array_key_exists(str_replace("'","",$custom_field), $allow_lookup)."****************"."<br>";					
//	echo "Amount *******".$allow_lookup[$custom_field]."****************"."<br>";					
//					setlocale(LC_MONETARY, 'en_US');
			if (array_key_exists(str_replace("'","",$custom_field), $allow_lookup)) { 
				// Code to balance out allowance from RLM to the employee store  SUSHI
					$adjust = (float)(str_replace("'","",$allowance))-$allow_lookup[str_replace("'","",$custom_field)];
//				echo "Match FOUND *******".$adjust."****************"."<br>";					
					if ($adjust) {
						$allowamount = number_format((float)(str_replace("'","",$allowance)),2);
						$description = "'Adjustment of total to the RLM amount of $$allowamount as of '";
//			echo("Something to adjust. [". $adjust."]=".$allow_lookup[str_replace("'","",$custom_field)]."-[".(float)(str_replace("'","",$allowance))."]");
						$sql3 ='';
						$sql3 = "INSERT INTO ".DB_PREFIX."customer_transaction (customer_transaction_id,customer_id,order_id,description,amount,date_added) VALUES ";
						$sql3 .= "(NULL,$lastoneend,0,CONCAT($description,DATE(NOW())),$adjust,NOW())";
						$sql3 .= ";";
//			 echo "ADJUST{".$sql3."}  hh<br>";
						$database->query($sql3);
						$newones++;
					} // Ignore if there is nothing to adjust
			} else {
					if ($lastoneend) {
						$adjust = (float)(str_replace("'","",$allowance));
						$description = "'Beginning Balance. Welcome to Alexander Wang. '";
						$sql3 ='';
						$sql3 = "INSERT INTO ".DB_PREFIX."customer_transaction (customer_transaction_id,customer_id,order_id,description,amount,date_added) VALUES ";
						$sql3 .= "(NULL,$lastoneend,0,CONCAT($description,DATE(NOW())),$adjust,NOW())";
						$sql3 .= ";";
//			 echo "NEW{".$sql3."}  hh<br>";
						$database->query($sql3);
						$newones++;
					}
			}
		}
		// final commit
		$database->query("COMMIT;");
//		$updatedones = $lastoneend-$firstone;
//die();
		// Store the status to be displayed on page
		$this->session->data['uploadstatus'] = "SUCCESS: $updateemp employee records were updated and $newones allowances were updated";

		return TRUE;
	}

/***** END OF EMPLOYEE MODULE ********/
/***** BEGIN OF INVOICE RECORD MODULE ********/
	function invoicerec( $filename ) {
		// we use our own error handler
		global $registry;
		$registry = $this->registry;
		set_error_handler('error_handler_for_rlmexport',E_ALL);
		register_shutdown_function('fatal_error_shutdown_handler_for_rlmexport');

		try {
			$database =& $this->db;
			$this->session->data['export_nochange'] = 1;

			$emplist=$this->loadEmployee($database);			
			
			// we use the PHPExcel package from http://phpexcel.codeplex.com/
			$cwd = getcwd();
			chdir( DIR_SYSTEM.'PHPExcel' );
			require_once( 'Classes/PHPExcel.php' );
			chdir( $cwd );
			// parse uploaded spreadsheet file
			$inputFileType = PHPExcel_IOFactory::identify($filename);
			$objReader = PHPExcel_IOFactory::createReader($inputFileType);
			$objReader->setReadDataOnly(true);
			$reader = $objReader->load($filename);
			
			// read the various worksheets and load them to the database
			$ok = $this->validateInvoice( $reader );
			if (!$ok) {
				return FALSE;
			}
			$this->clearCache();
			$this->session->data['export_nochange'] = 0;
			
			$ok = $this->uploadInvoice( $reader, $emplist, $database );
			if (!$ok) {
				return FALSE;
			}
			return $ok;
			
		} catch (Exception $e) {
			$errstr = $e->getMessage();
			$errline = $e->getLine();
			$errfile = $e->getFile();
			$errno = $e->getCode();
			$this->session->data['export_error'] = array( 'errstr'=>$errstr, 'errno'=>$errno, 'errfile'=>$errfile, 'errline'=>$errline );
			if ($this->config->get('config_error_log')) {
				$this->log->write('PHP ' . get_class($e) . ':  ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
			}
			return FALSE;
		}
	}

	function validateInvoice( &$reader ) 
	{
		$sheetNames = $reader->getSheetNames();
		if ($sheetNames[0] != 'AR Invoice Register') {
			 error_log(date('Y-m-d H:i:s - ', time()).$this->language->get( 'error_sheet_count' )."\n",3,DIR_LOGS."error.txt");
			return FALSE;					
		}
		return TRUE;
	}

	function uploadInvoice( &$reader, &$emplist, &$database ) {

		$data = $reader->getSheet(0);
		$invoices = array();
		$invoice = array();
		$name = array();
		$invcol = $this->invcol();
		$isFirstRow = TRUE;
		$isHeaderRow = TRUE;
		$i = 0;
		$k = $data->getHighestRow();
		for ($i=0; $i<$k; $i+=1) {
			if ($isFirstRow) {
				$isFirstRow = FALSE;
				continue;
			}
			if ($isHeaderRow) {
				$isHeaderRow = FALSE;
				continue;
			}						
			$customer_id = "'".$this->getCell($data, $i, $invcol['CustID'])."'";
// GOD
//echo $customer_id."  [".$emplist['PERS760']."] <br> ";
//echo $customer_id."  [".$emplist[str_replace("'","",$customer_id)]."] <br> ";
			if (array_key_exists(str_replace("'","",$customer_id),$emplist)) {
				$invoices[$i]['customer_id']=$emplist[str_replace("'","",$customer_id)];
			} else {
				continue;
			}
			
			$invoices[$i]['customer_invoice_id']= 'NULL';
			$invoices[$i]['invoice_id']=$this->getCell($data, $i, $invcol['InvoiceNum']);
			$invoices[$i]['invoice_date']="STR_TO_DATE('".$this->getCell($data, $i, $invcol['InvoiceDate'])."', '%m/%d/%Y')";
			$invoices[$i]['order_num']=$this->getCell($data, $i, $invcol['OrderID']);
			$invoices[$i]['cust_po']=$this->getCell($data, $i, $invcol['CustPO']);
			$invoices[$i]['subdivname']=$this->getCell($data, $i, $invcol['SubDivName']);
			$invoices[$i]['units']=$this->getCell($data, $i, $invcol['Units']);
			$invoices[$i]['std_amount']=$this->getCell($data, $i, $invcol['StdAmount']);
			$invoices[$i]['tax_amount']=$this->getCell($data, $i, $invcol['TaxAmount']);
			$invoices[$i]['ar_amount']=$this->getCell($data, $i, $invcol['ARAmount']);
			$invoices[$i]['date_added']='NOW()';
		}
		return $this->storeInvoice( $database, $invoices );
	}
	
	function invcol(){
		return array ('InvoiceNum'=>1,'InvoiceDate'=>2,'CustID'=>7,'OrderID'=>10,'CustPO'=>11,'SubDivName'=>18,'Units'=>29,'StdAmount'=>32,'TaxAmount'=>36,'ARAmount'=>40);
	}	
	
	function storeInvoice( &$database, &$invoices ) 
	{
		$sql = "";
		// start transaction, 
		$sql = "START TRANSACTION;\n";
		// disable all accounts
		$sql .= "SET SQL_SAFE_UPDATES=0;\n";
		$this->multiquery( $database, $sql );
		$sql='';
		$database->query($sql);
		$database->query("COMMIT;");
		//keep track of new products and updated ones
		$newones = 0;
		$updateinvoice = 0;
		$firstone = $database->getLastId();

		foreach ($invoices as $invoice) {
			$customer_invoice_id = $database->escape($invoice['customer_invoice_id']);
			$invoice_id = $invoice['invoice_id'];
//			$invoice_date = $database->escape($invoice['invoice_date']);
			$invoice_date = $invoice['invoice_date'];
			$customer_id = $invoice['customer_id'];
			$order_num = $invoice['order_num'];
			$cust_po = "'".$invoice['cust_po']."'";
			$subdivname = "'".$database->escape($invoice['subdivname'])."'";
			$units = $database->escape($invoice['units']);
			$std_amount = "'".$database->escape($invoice['std_amount'])."'";
			$tax_amount = "'".$database->escape($invoice['tax_amount'])."'";
			$ar_amount = "'".$database->escape($invoice['ar_amount'])."'";
			$date_added = $database->escape($invoice['date_added']);

			$lastonestart = $database->getLastId();
			$sql = '';
			$sql  = "INSERT INTO ".DB_PREFIX."customer_invoice (customer_invoice_id,invoice_id,invoice_date,customer_id,order_num,cust_po,subdivname,units,std_amount,tax_amount,ar_amount,date_added)";
			$sql .= " VALUES ";
			$sql .= "($customer_invoice_id,$invoice_id,$invoice_date,$customer_id,$order_num,$cust_po,$subdivname,$units,$std_amount,$tax_amount,$ar_amount,$date_added)";
			$sql .= " ON DUPLICATE KEY UPDATE ";
			$sql .= "invoice_id=VALUES(invoice_id),invoice_date=VALUES(invoice_date),customer_id=VALUES(customer_id),order_num=VALUES(order_num),cust_po=VALUES(cust_po),subdivname=VALUES(subdivname),units=VALUES(units),std_amount=VALUES(std_amount),tax_amount=VALUES(tax_amount),ar_amount=VALUES(ar_amount),date_added=VALUES(date_added);";
//die($sql);
 			$database->query($sql);
			$lastoneend = $database->getLastId();
			$updateinvoice++;
		}
		// final commit
		$database->query("COMMIT;");
		// Store the status to be displayed on page
		$this->session->data['uploadstatus'] = "SUCCESS: $updateinvoice invoice records were updated";

		return TRUE;
	}

/***** END OF INVOICE MODULE ********/
}
?>