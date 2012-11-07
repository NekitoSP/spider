<?php
	abstract class SiteParser {
		abstract protected function parse(); // for parse

		public function xmlObjToArr($obj) { 
			$namespace = $obj->getDocNamespaces(true); 
			$namespace[NULL] = NULL; 
        
			$children = array(); 
			$attributes = array(); 
			$name = strtolower((string)$obj->getName()); 

			$text = trim((string)$obj); 
			if( strlen($text) <= 0 ) { 
				$text = NULL; 
			} 

			// get info for all namespaces 
			if(is_object($obj)) { 
				foreach( $namespace as $ns=>$nsUrl ) { 
					// atributes 
					$objAttributes = $obj->attributes($ns, true); 
					foreach( $objAttributes as $attributeName => $attributeValue ) { 
						$attribName = strtolower(trim((string)$attributeName)); 
						$attribVal = trim((string)$attributeValue); 
						if (!empty($ns)) { 
							$attribName = $ns . ':' . $attribName; 
						} 
						$attributes[$attribName] = $attribVal; 
					} 

					// children 
					$objChildren = $obj->children($ns, true); 
					foreach( $objChildren as $childName=>$child ) { 
						$childName = strtolower((string)$childName); 
						if( !empty($ns) ) { 
							$childName = $ns.':'.$childName; 
						} 
						$children[$childName][] = $this::xmlObjToArr($child); 
					} 
				} 
			} 
			$ret = array( 
				//'name'=>$name, 
				//'text'=>$text, 
				//'attributes'=>$attributes, 
				//'children'=>$children 
			); 
			if (!$text==NULL) $ret['text'] = $text;
			if (count($attributes)>=1) $ret['attributes'] = $attributes;
			if (count($children)>=1) $ret['children'] = $children;
			return $ret;
		} 
	}

	class HHParser extends SiteParser{
		private $region;			//регион
		private $professionalField;	//проф.область
		public function HHParser(){
			//Регион: Башкортостан - 1347
			//проф.область: IT - 1
			
			//Сортировка: по дате изменения - 2 //not for constructor
		}

		public function parse(){
			//качаем страничку с hh.ru
			if( $curl = curl_init() ) {
				curl_setopt($curl, CURLOPT_URL, 'http://api.hh.ru/1/xml/vacancy/search/?region=1347&order=2&field=1&items=1000');
				curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
				$out = curl_exec($curl);
				//echo $out;
				curl_close($curl);
			}
			
			//парсим файл
			$doc = new SimpleXMLElement($out);
			$parsedArr = parent::xmlObjToArr($doc);
			
			//получаем дату последнго апдейта из БД по последней записи
			$result = db_query_range('SELECT updated FROM {spider_joblist} ORDER BY updated DESC', 0, 1);
			$accLast = db_fetch_array($result);

			//цикл по скачанным вакансиям
			foreach($parsedArr["children"]["vacancies"][0]["children"]["vacancy"] as $k => $val){
				$jID = $val["attributes"]["id"];
				if( $curl = curl_init() ) {
					curl_setopt($curl, CURLOPT_URL, 'http://api.hh.ru/1/xml/vacancy/'.$jID.'/');
					curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
					$out2 = curl_exec($curl);
					curl_close($curl);
				}
				$doc = new SimpleXMLElement($out2);
				$jobPage = parent::xmlObjToArr($doc);

				$jobDescription = '';
				if (isset($jobPage["children"]["description"][0]["text"])){
					$jobDescription = $jobPage["children"]["description"][0]["text"];
				}
				
				//$jLINK = $val["children"]["link"][1]["attributes"]["href"];
				$jobCaption = $val["children"]["name"][0]["text"];
				
				$jCOMPID = 0;
				if (isset($val["children"]["employer"][0]["attributes"]["id"])){
					$jCOMPID  = $val["children"]["employer"][0]["attributes"]["id"];
				}
				$jUPDATETIME = $val["children"]["update"][0]["attributes"]["timestamp"];
				
				$jWAGEFROM = 0;
				if (isset( $val["children"]["salary"][0]["children"]["from"][0]["text"] )){
					$jWAGEFROM = $val["children"]["salary"][0]["children"]["from"][0]["text"];
				}
				
				$jWAGETO = 0;
				if (isset( $val["children"]["salary"][0]["children"]["to"][0]["text"] )){
					$jWAGETO = $val["children"]["salary"][0]["children"]["to"][0]["text"];
				}
				
				$jWAGECURRENCY = "N/A";
				if (isset( $val["children"]["salary"][0]["children"]["currency"][0]["text"] )){
					$jWAGECURRENCY = $val["children"]["salary"][0]["children"]["currency"][0]["text"];
				}

				//вакансия "свежа", добавим в базу или обновим если есть
				if (!isset($accLast["updated"]) || $accLast["updated"]<$jUPDATETIME){
					$res = db_query('SELECT * FROM {spider_joblist} WHERE id = %d',$jID);
					$arr = db_fetch_array($res);
					//if not exist = INSERT NEW, else = update existing
					if ($arr == FALSE){
						db_query('INSERT INTO {spider_joblist} (`id`, `name`, `compid`, `updated`, `wagefrom`, `wageto`, `wagecurrency`, `jobdescription`) VALUES (%d, \'%s\', %d, %d, %d, %d, \'%s\', \'%s\');',$jID, $jobCaption, $jCOMPID, $jUPDATETIME, $jWAGEFROM, $jWAGETO, $jWAGECURRENCY, $jobDescription);
					} else {
						db_query("UPDATE {spider_joblist} SET `name`='$jobCaption', `compid`='$jCOMPID', `updated`='$jUPDATETIME', `wagefrom`='$jWAGEFROM', `wageto`='$jWAGETO', `wagecurrency`='$jWAGECURRENCY', `jobdescription`='$jobDescription' WHERE `id`='$jID' ");
					}
				}

				//var_dump($accLast["updated"]);
				$retnArr[$k]["id"] = $jID;
				$retnArr[$k]["text"] = $jobCaption;
				$retnArr[$k]["compid"] = $jCOMPID;
				$retnArr[$k]["update"] = $jUPDATETIME;
				$retnArr[$k]["wagefrom"] = $jWAGEFROM;
				$retnArr[$k]["wageto"] = $jWAGETO;
				$retnArr[$k]["wagecurrency"] = $jWAGECURRENCY;
				$retnArr[$k]["jobdescription"] = $jobDescription;
			}
			//var_dump($retnArr);
			//STUPID COMMENT
		}
	}
	//$p = new HHParser();
	//$p->parse();
?>