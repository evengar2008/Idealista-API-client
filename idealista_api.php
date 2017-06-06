<?php

class Idealista {

	private $res; //response
	private $pageNumber;
	private $version;
	private $key = 'fswkp66erz840wecv74krjb7g6bs3yg9';
	private $secret = 'R7i77cqhitOi';
	private $mapping;
	private $country;
	private $viewButton;

	/**
	 * Constructor
	 * @param type $res - response-Object
	 */
	public function __construct($res) {
		$this->res = $res;
		$this->pageNumber = 1;
		$this->setCountryOptions();

		$this->mapping = $this->getMapping();
	}

	//Mapping fuer verschiedene
	private function getMapping(  ) {
		return array(
			// rent
			//Квартиры
			//68 => array('operation' => "rent", 'propertyType' => '2'),
			//Дома
			84 => array('operation' => "rent", 'propertyType' => 'homes'),
			// Временная аренда
			//83 => array('category_id' => "", 'nedvigimost_type' => '2'),
			//Коммунальная квартира
			34 => array('operation' => "rent", 'propertyType' => 'bedrooms'),
			//Гаражи / Автостоянки
			82 => array('operation' => "rent", 'propertyType' => 'garages'),
			//Санатории
			//81 => array('property_type' => ""),
			//Коммерческая недвижимость
			41 => array('operation' => "rent", 'propertyType' => 'premises'),

			//sale
			//Квартиры
			//80 => array('category_id' => "2", 'nedvigimost_type' => '1'),
			//Участки
			//79 => array('category_id' => "5", 'nedvigimost_type' => '1'),
			//Дома
			78 => array('operation' => "sale", 'propertyType' => 'homes'),
			//Гаражи / Автостоянки
			77 => array('operation' => "sale", 'propertyType' => 'garages'),
			//Распродажа с аукциона
			//76 => array('property_type' => "compulsoryauction"),
			//Инвестиционная
			//75 => array('property_type' => "investment"),
			//Готовые и  Монолитные дома
			//74 => array('property_type' => "houses", 'listing_status' => 'sale'),
		);
	}

	private function setCountryOptions(  ) {
		$country = !empty($_POST['idealistaCountry'])
			? $_POST['idealistaCountry']
			: 'es';

		switch ($country) {
			case 'es':
				$this->country = 'es';
				$this->viewButton = 'Ver';
				break;
			case 'it':
				$this->country = 'it';
				$this->viewButton = 'Vista';
				break;
			case 'pt':
				$this->country = 'pt';
				$this->viewButton = 'Visão';
				break;
		}
	}

	/**
	 * Initate the Search for results
	 * @return type null
	 */
	public function search() {
		$this->pageNumber = filter_input(INPUT_POST, 'pageNumber', FILTER_SANITIZE_NUMBER_INT);
		$this->version = (filter_input(INPUT_POST, 'version', FILTER_SANITIZE_STRING) == 'true') ? true : false;


		$property_type = $this->getPropertyType();
		if ($property_type === null) {
			return;
		}

		$aParameter = $this->getSearchParameter($property_type);
		$estates = $this->getEstates($aParameter);

		$this->buildEstates($estates);
	}

	protected function getEstates( $aParameter ) {
		if (empty($aParameter['center'])) {
			return array();
		}

		// request token
		$basic_credentials = base64_encode($this->key.':'.$this->secret);
		$tk = curl_init('https://api.idealista.com/oauth/token');
		curl_setopt($tk, CURLOPT_HTTPHEADER,
			array('Authorization: Basic '.$basic_credentials, 'Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($tk, CURLOPT_POST,        true);
		curl_setopt($tk, CURLOPT_POSTFIELDS, 'grant_type=client_credentials&scope=read');
		curl_setopt($tk, CURLOPT_RETURNTRANSFER, true);
		$token = json_decode(curl_exec($tk));
		curl_close($tk);

		// use token
		if (! isset($token->token_type) || $token->token_type !== 'bearer') {
			return array();
		}

		$br = curl_init("https://api.idealista.com/3.5/{$this->country}/search");
		curl_setopt($br, CURLOPT_POST, true);
		curl_setopt($br, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$token->access_token, 'Content-Type: multipart/form-data'));
		curl_setopt($br, CURLOPT_POSTFIELDS, $aParameter);
		curl_setopt($br, CURLOPT_RETURNTRANSFER, true);
		$data = curl_exec($br);
		curl_close($br);

		return json_decode($data);
	}

	/**
	 * Get wanted information out of resultset
	 * @param type $estates response from API
	 * @return type null
	 */
	private function buildEstates($estates) {
		$estateList = array();

//		print_r($estates);

		if (!is_array($estates->elementList)) {
			return;
		}
		$this->res->result_count = $estates->total;
		foreach ($estates->elementList as $estate) {
			$item = new stdClass();
			$item->id = $estate->propertyCode;
			$item->title = $estate->suggestedTexts->title;
			if (mb_strlen($item->title) > 90) {
				$item->title = substr($item->title, 0, 90) . "...";
			}
			$item->address = $estate->address;
			$item->price = $estate->price;
			$item->rooms = $estate->rooms;
			$item->picture = $estate->thumbnail;
			$item->url = $estate->url;
			$item->country = $estate->country;
			$estateList[] = $item;
		}

		//get number of current page
		$pageNumber = $this->pageNumber;
		//get number of all pages
		$numberOfPages = $estates->totalPages;

		$this->res->estates = $estateList;
		$this->genHTML($estateList, $pageNumber, $numberOfPages);
	}

	private function getSearchParameter($property_type) {
		$aParameter = array(
			'apikey' => 'fswkp66erz840wecv74krjb7g6bs3yg9',
			'center' => '',
			'distance' => '10000',
			'propertyType' => 'homes',
			'operation' => 'sale',
			'country' => $this->country,
			'locale' => $this->country,
			'numPage' => $this->pageNumber,
		);

		if (!empty($_POST['keywords'])) {
			$location = $this->getLocation(trim($_POST['keywords']));
			$aParameter['center'] = !empty($location)
				? $location['lat'] . ',' . $location['lng']
				: '';
		}

		if (isset($_POST['range-to:preis'])) {
			$priceTo = $_POST['range-to:preis'];
		}

		if (isset($_POST['range-from:preis'])) {
			$priceFrom = $_POST['range-from:preis'];
		}

		if (strlen($priceTo . $priceFrom) > 0) {
			$aParameter['minPrice'] = $priceFrom;
			$aParameter['maxPrice'] = $priceTo;
		}

		$aParameter = array_merge($aParameter, $this->getRoomType($property_type));
		$aParameter = array_merge($aParameter, $this->getMappedType($property_type));
//		$this->res->debug = $aParameter;

		return $aParameter;
	}

	/**
	 * Get geo-location by keywords
	 *
	 * @param $string
	 *
	 * @return array|null
	 */
	private function getLocation($string){
		$string = urlencode($string);
		$country = strtoupper($this->country);
		$details_url = "http://maps.googleapis.com/maps/api/geocode/json?address=".$string."&sensor=false&country={$country}";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $details_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = json_decode(curl_exec($ch), true);

		if ($response['status'] != 'OK') {
			return null;
		}

		$geometry = $response['results'][0]['geometry'];

		return array(
			'lat' => $geometry['location']['lat'],
			'lng' => $geometry['location']['lng'],
		);
	}

	private function getPropertyType(  ) {
		$category = $_POST['category'];
		if (is_array($category) && count($category) >= 1 && array_key_exists($category[1], $this->mapping)) {
			return $category[1];
		}

		$this->res->message = 'Our partnership with "Idealista" does not cover %categoryname% object.';
		return null;
	}

	private function getRoomType( $property_type ) {
		if (!in_array($property_type, array(84, 78))) {
			return array();
		}

		$room = $roomTo = $roomFrom = 0;
		if (isset($_POST['range-to:anzahl_zimmer'])) {
			$roomTo = intval($_POST['range-to:anzahl_zimmer']);
		}

		if (isset($_POST['range-from:anzahl_zimmer'])) {
			$roomFrom = intval($_POST['range-from:anzahl_zimmer']);
		}

		if ($roomFrom > 0) {
			$room = $roomFrom;
		} elseif ($roomTo > 0) {
			$room = $roomTo;
		}

		if ($room == 0) {
			return array();
		}

		return array(
			'bedrooms' => $room
		);
	}

	private function getMappedType($property_type) {
		$mapped = array('operation' => "rent", 'propertyType' => 'homes');
		if (array_key_exists($property_type, $this->mapping)) {
			$mapped = $this->mapping[$property_type];
		}

		return $mapped;
	}

	/**
	 * Generates HTMl response
	 * @param type $estateList Array of estate-Objects
	 */
	private function genHTML($estateList, $pageNumber, $numberOfPages) {
		//if more than one page
		if ($numberOfPages > 0) {
			$paging = '';

			//show page number from and to (show up to 5 paging links)
			$from = ($pageNumber > 1) ? $pageNumber - 1 : $pageNumber;
			$from = ($pageNumber > 2) ? $pageNumber - 2 : $from;
			$to = ($pageNumber < $numberOfPages) ? $pageNumber + 1 : $pageNumber;
			$to = ($pageNumber < $numberOfPages - 1) ? $pageNumber + 2 : $to;

			//add pages select
			for ($i = $from; $i <= $to; $i++) {
				$paging .= '<a href="#top" data-num="' . $i . '" class="paging-link' . (($i == $pageNumber) ? ' current' : '') . '">' . $i . '</a>';
			}

			//add next button if there is one
			if ($pageNumber < $numberOfPages) {
				$paging .= '<a  href="#top" data-num="' . ($pageNumber + 1) . '" class="paging-link"><pan class="glyphicon glyphicon-chevron-right"></span></a>';
			}
			//add back button if not first page
			if ($pageNumber > 1) {
				$paging = '<a  href="#top" data-num="' . ($pageNumber - 1) . '" class="paging-link"><pan class="glyphicon glyphicon-chevron-left"></span></a>' . $paging;
			}
			//back to the start (showed on after fourth page)
			if ($pageNumber > 4) {
				$paging = '<a  href="#top" data-num="1" class="paging-link"><pan class="glyphicon glyphicon-fast-backward"></span></a>' . $paging;
			}

			$this->res->paging = $paging;
		}

		$htmlList = array();
		foreach ($estateList as $estate) {
			if ($this->version) {
				$htmlList[] = $this->getItemHTMLMobile($estate);
			} else {
				$htmlList[] = $this->getItemHTML($estate);
			}
		}
		$this->res->version = $this->version;
		$this->res->html = $htmlList;
	}

	/**
	 * Generate HTML for single Estate-Object
	 * @param Object Estate-Object
	 * @return string HTML
	 */
	private function getItemHTML($estate) {
		ob_start();
		?>
		<li class="listing large">
			<a href="<?= $estate->url ?>" class="outline">
				<span style="background-image: url(<?= (strlen($estate->picture) > 1) ? $estate->picture : '/assets/images/kein-bild-vorhanden.png' ?>)" class="preview"></span>


			</a><div class="detail-wrapper"><a href="<?= $estate->url ?>" class="outline">
					<p class="price"><span class="number"><?= ( number_format($estate->price, 0, '', ' ') > 0) ? number_format($estate->price, 0, '', ' ') ."&euro;" : '' ?></span> </p>
					<p class="title"><?= $estate->title ?></p>
					<p class="address"><?= $estate->address ?></p>

				</a><a class="button red button" href="<?= $estate->url ?>"><?php echo $this->viewButton; ?></a>
				<span class="provider idealista float-right"></span>

			</div>

		</li>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate HTML for single Estate-Object (mobile Version)
	 * @param Object Estate-Object
	 * @return string HTML
	 */
	private function getItemHTMLMobile($estate) {
		ob_start();
		?>

		<div class="entry mobile">

			<a data-event="Immobilien:ImmobilienScout24 Image" href="<?= $estate->url ?>" target="_blank" class="immobilien-search-preview-mobile" style="background-image: url(<?= (strlen($estate->picture) > 1) ? $estate->picture : '/assets/images/kein-bild-vorhanden.png' ?>);">

			</a>

			<div class="row whiteout result">
				<div class="col-xs-12">

					<a data-event="Immobilien:ImmobilienScout24" href="<?= $estate->url ?>" target="_blank">
						<h2><?= $estate->title ?></h2>
					</a>

					<p><?= $estate->address ?></p>
					<!-- Price format without decimal part of the number -->
					<p><?= ( number_format($estate->price, 0, '', ' ') > 0) ? number_format($estate->price, 0, '', ' ') . "&#8381;" : '' ?><?= ((strlen($estate->rooms) > 0) ? ' | ' . $estate->rooms . ' Zimmer' : '') ?><?= ((number_format($estate->livingSpace, 0) > 0) ? ' | ' . number_format($estate->livingSpace, 0) . '  m²' : '') ?>
						<?= ((number_format($estate->roomSize, 0) > 0) ? ' | ' . number_format($estate->roomSize, 0) . '  m² Zimmer' : '') ?>
						<?= ((number_format($estate->netFloorSpace, 0) > 0) ? ' | ' . number_format($estate->netFloorSpace, 0) . '  m² Erdgeschoss (Netto)' : '') ?></p>

				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

}
