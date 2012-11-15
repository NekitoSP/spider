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
			if (!$text==NULL) $ret['text'] = $text;
			if (count($attributes)>=1) $ret['attributes'] = $attributes;
			if (count($children)>=1) $ret['children'] = $children;
			return $ret;
		} 
	}

	class HHParser extends SiteParser{
		private $region;			//регион
		private $professionalField;	//проф.область

		public function HHParser($params){
			$region = $params->region;
			$professionalField = $params->field;
			//Регион: Башкортостан - 1347
			//проф.область: IT - 1
		}

		public function parse(){
			$spiderName = "hhunt";
			//качаем страничку с hh.ru, пока без пагинации, последние 1000 записей
			if( $curl = curl_init() ) {
				curl_setopt($curl, CURLOPT_URL, 'http://api.hh.ru/1/xml/vacancy/search/?region=$region&order=2&field=$professionalField&items=10');
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
				$jobId = $val["attributes"]["id"];

				$jobCompanyId = 0;
				if (isset($val["children"]["employer"][0]["attributes"]["id"])){
					$jobCompanyId  = $val["children"]["employer"][0]["attributes"]["id"];
				}

				//скачаем описание вакансии
				unset($out);
				if( $curl = curl_init() ) {
					curl_setopt($curl, CURLOPT_URL, 'http://api.hh.ru/1/xml/vacancy/'.$jobId.'/');
					curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
					$out = curl_exec($curl);
					curl_close($curl);
				}
				$doc = new SimpleXMLElement($out);
				$jobPage = parent::xmlObjToArr($doc);

				$jobDescription = '';
				if (isset($jobPage["children"]["description"][0]["text"])){
					$jobDescription = $jobPage["children"]["description"][0]["text"];
				}

				$jobCaption = '';
				if (isset($val["children"]["name"][0]["text"])){
					$jobCaption = $val["children"]["name"][0]["text"];
				}

				$jobLastUpdateTime = 0;
				if (isset($val["children"]["update"][0]["attributes"]["timestamp"])){
					$jobLastUpdateTime = $val["children"]["update"][0]["attributes"]["timestamp"];
				}

				$jobWageFrom = 0;
				if (isset( $val["children"]["salary"][0]["children"]["from"][0]["text"] )){
					$jobWageFrom = $val["children"]["salary"][0]["children"]["from"][0]["text"];
				}

				$jobWageTo = 0;
				if (isset( $val["children"]["salary"][0]["children"]["to"][0]["text"] )){
					$jobWageTo = $val["children"]["salary"][0]["children"]["to"][0]["text"];
				}

				$jobWageCurrency = "N/A";
				if (isset( $val["children"]["salary"][0]["children"]["currency"][0]["text"] )){
					$jobWageCurrency = $val["children"]["salary"][0]["children"]["currency"][0]["text"];
				}

				//проверить наличие данной компании в таблице компаний, иначе - распарсим и добавим
				$companyExists = db_fetch_array(db_query("SELECT * FROM {spider_companies} WHERE id=%d",$jobCompanyId));
				//echo $companyExists;
				if($companyExists == FALSE){
					if( $curl = curl_init() ) {
						curl_setopt($curl, CURLOPT_URL, 'http://api.hh.ru/1/xml/employer/'.$jobCompanyId.'/');
						curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
						$outCompany = curl_exec($curl);
						curl_close($curl);
					}
					$doc = new SimpleXMLElement($outCompany);
					$companyPage = parent::xmlObjToArr($doc);

					$companyName = "";
					if(isset($companyPage["children"]["name"][0]["text"])){
						$companyName = $companyPage["children"]["name"][0]["text"];
					}

					$companyLogo = "";
					if(isset($companyPage["children"]["logos"][0]["children"]["link"][1]["attributes"]["href"])){
						$companyLogo = $companyPage["children"]["logos"][0]["children"]["link"][1]["attributes"]["href"];
					}

					$companyUrl = "";
					if(isset($companyPage["children"]["link"][2]["attributes"]["href"])){
						$companyUrl = $companyPage["children"]["link"][2]["attributes"]["href"];
					}

					$companyDescription = "";
					if(isset($companyPage["children"]["full-description"][0]["text"])){
						$companyDescription = $companyPage["children"]["full-description"][0]["text"];
					}
					//добавим работодателя в базу
					db_query("INSERT INTO {spider_companies} (`id`, `name`, `site`, `logo`, `about`) VALUES (%d, '%s','%s','%s','%s');",$jobCompanyId, $companyName, $companyUrl, $companyLogo, $companyDescription);
				}

				//вакансия "свежа", добавим в базу или обновим если есть
				if (!isset($accLast["updated"]) || $accLast["updated"]<$jobLastUpdateTime){
					$res = db_query('SELECT * FROM {spider_joblist} WHERE id = %d',$jobId);
					$arr = db_fetch_array($res);
					//if not exist = INSERT NEW, else = update existing
					if ($arr == FALSE){
						db_query("INSERT INTO {spider_joblist} (`id`, `spidername`,`name`, `companyid`, `updated`, `wagefrom`, `wageto`, `wagecurrency`, `jobdescription`) VALUES (%d, '%s', '%s', %d, %d, %d, %d, '%s', '%s');",$jobId, $spiderName,$jobCaption, $jobCompanyId, $jobLastUpdateTime, $jobWageFrom, $jobWageTo, $jobWageCurrency, $jobDescription);
					} else {
						db_query("UPDATE {spider_joblist} SET `name`='$jobCaption', `compid`='$jobCompanyId', `updated`='$jobLastUpdateTime', `wagefrom`='$jobWageFrom', `wageto`='$jobWageTo', `wagecurrency`='$jobWageCurrency', `jobdescription`='$jobDescription' WHERE `id`='$jobId' ");
					}
				}

				//var_dump($accLast["updated"]);
				/*
				$retnArr[$k]["id"] = $jobId;
				$retnArr[$k]["text"] = $jobCaption;
				$retnArr[$k]["compid"] = $jobCompanyId;
				$retnArr[$k]["update"] = $jobLastUpdateTime;
				$retnArr[$k]["wagefrom"] = $jobWageFrom;
				$retnArr[$k]["wageto"] = $jobWageTo;
				$retnArr[$k]["wagecurrency"] = $jobWageCurrency;
				$retnArr[$k]["jobdescription"] = $jobDescription;
				*/
			}
		}
	}
	//$params = "{'region' = '1347', 'field' = '1'}";
	//$parser = new HHParser(json_decode($params));
	//$parser->parse();

	/*
	TODO:
		функция для получения параметров и их читабельных вариантов каждого парсера
	*/
