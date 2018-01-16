<?php
  /**
   * PHP script - Address string parser
   * load data from account_MNT table
   * Driver: PDO_MySQL
   * @author   Daniel Roth <danielrth9@hotmail.com>
   */

  error_reporting(E_ALL ^ E_NOTICE);
  define('HOST', '127.0.0.1');
  define('USER', 'root');
  define('PWD', 'root');
  define('DB', 'faccess_master_bk_db');

  define('USPS_USER_ID', "612SELFE4850");
  define('API_BASE_URL', "http://production.shippingapis.com/ShippingAPI.dll?API=verify&XML=");

  define('LIST_STREET_SUFFIX', file( "list_st_suffix.txt", FILE_IGNORE_NEW_LINES ));
  define('LIST_2ND_DESIGNATOR', file( "list_2nd_des.txt", FILE_IGNORE_NEW_LINES ));

  Class cPDO extends PDO {
      private $host = HOST, $user = USER, $pwd = PWD, $db = DB, $port = '8889', $sgbd = 'mysql', $conn;

      public function __construct() {
          try {
            $strConn = "{$this->sgbd}:port={$this->port};host={$this->host};dbname={$this->db}";
            $this->conn = new PDO($strConn, $this->user, $this->pwd);
            $this->conn->exec('SET NAMES utf8');
          } catch (PDOException $e) {
            echo $e->getMessage();
          }
      }

      public function fetchAddresses() {
        $addrs = array();
        try {
              $sql = "SELECT addr1, addr2, city, state, zip  FROM account_MNT_2017_11 ORDER BY AcctID DESC";
              foreach ($this->conn->query($sql) as $row) {
                $addrs[] = $row;
              }
          } catch (PDOException $e) {
              return $e->getMessage();
          }
          return $addrs;
      }

      public function endC() {
          $this->conn = null;
      }
  }

  function callAPIByCurl($curl, $url) {
    curl_setopt($curl, CURLOPT_URL, $url);
    $resp = curl_exec($curl);
    $strResp = '';
    if(curl_error($curl))
        $strResp = '';
    else if ( str_replace(' ', '', $resp) == '' )
      $strResp = '';
    else
      $strResp = $resp;

    return $strResp;
  }

  function convertXML2Obj($strXml) {
    try {
      $xml = simplexml_load_string($strXml);
      $obj = json_decode(json_encode($xml))->Address;
      if ($obj->Error)
        return false;
      else
        return $obj;
    } catch (Exception $e) {
      return false;
    }
  }

  function getAddressElements($strAddr1, $strAddr2) {
    $wrds = explode(' ', $strAddr2);
    $arrEles = array();

    //primary number
    if ((int)$wrds[0])
      $arrEles['primary_number'] = $wrds[0];

    if (count($wrds) < 2)
      return $arrEles;
    
    //street suffix
    for ($i = 2; $i < count($wrds); $i++) {
      if ( in_array( $wrds[$i], LIST_STREET_SUFFIX ) ) {
        $arrEles['street_suffix'] = $wrds[$i];
        
        // street name
        $stName = "";
        for ($j = 1; $j < $i; $j++)
          $stName .= $wrds[$j] . ' ';
        $arrEles['street_name'] = trim($stName);

        break;
      }
    }

    //secondary designator
    if ($strAddr1) {
      $wrds = explode(' ', $strAddr1);

      for ($i = 0; $i < count($wrds); $i++) {
        if ( in_array( $wrds[$i], LIST_2ND_DESIGNATOR ) ) {
            $arrEles['secondary_designator'] = $wrds[$i];
            
            // secondary number
            if ( $i < count($wrds)-1 )
              $arrEles['secondary_number'] = $wrds[$i+1];

            break;
          }
        }
    }

    return $arrEles;
  }
  /*
  //
  */
  echo ("Start: " . date('h:i:s A') . "\n");
  flush();
  ob_flush();

  $pdObj = new cPDO();
  $addrs = $pdObj->fetchAddresses();
  if (count($addrs) == 0) 
    die("No address found.<br>");
  
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

  for ($i = 0; $i < count($addrs); $i++) {
    // if ($i > 0) continue;
    $requestXML = "<AddressValidateRequest USERID=\"".USPS_USER_ID."\"><Revision>1</Revision><Address>";
    $requestXML .= "<Address1>" . $addrs[$i]['addr1'] . "</Address1>";
    $requestXML .= "<Address2>" . $addrs[$i]['addr2'] . "</Address2>";
    $requestXML .= "<City>" . $addrs[$i]['city'] . "</City>";
    $requestXML .= "<State>" . $addrs[$i]['state'] . "</State>";
    $requestXML .= "<Zip5>" . $addrs[$i]['zip'] . "</Zip5>";
    $requestXML .= "<Zip4></Zip4></Address></AddressValidateRequest>";

    $xmlFormattedAddr = callAPIByCurl( $curl, API_BASE_URL . urlencode($requestXML) );
    if ($xmlFormattedAddr)
      $xmlFormattedAddr = convertXML2Obj($xmlFormattedAddr);
    else
      continue;

    $ret = "Addr: ";
    $ret .= $addrs[$i]['addr1'] . " - ";
    $ret .= $addrs[$i]['addr2'];

    if ($xmlFormattedAddr->Address2) {
      if ($xmlFormattedAddr->Address1)
        $eles = getAddressElements($xmlFormattedAddr->Address1, $xmlFormattedAddr->Address2);
      else
        $eles = getAddressElements(false, $xmlFormattedAddr->Address2);
      $ret .= '  Elements: '.$eles['primary_number'].','
        .$eles['street_suffix'].','
        .$eles['street_name'].','
        .$eles['secondary_designator'].','
        .$eles['secondary_number'];
    }

    echo $ret . "\n";
    flush();
    ob_flush();
  }

  $pdObj -> endC();
  curl_close($curl);
  echo ("End: " . date('h:i:s A') . "\n");
  flush();
  ob_flush();
?>