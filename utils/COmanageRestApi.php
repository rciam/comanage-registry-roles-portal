<?php

class COmanageRestApi
{
  private $apiBaseURL;

  private $username;

  private $password;

  /**
   * COmanageRestApi constructor.
   * @param $apiBaseURL
   * @param $username
   * @param $password
   */
  public function __construct($apiBaseURL, $username, $password)
  {
    $this->apiBaseURL = $apiBaseURL;
    $this->username = $username;
    $this->password = $password;
  }


  public function addOrgIdentity($coId, $affiliation = null, $o = null)
  {
    $url = $this->apiBaseURL . "/org_identities.json";
    $req = '{'
      . '"RequestType":"OrgIdentities",'
      . '"Version":"1.0",'
      . '"OrgIdentities":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . (is_null($affiliation) ? '' : '     "Affiliation":"' . $affiliation. '",')
      . (is_null($o) ? '' : '     "O":"' . $o. '",')
      . '     "CoId":"' . $coId . '"'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  public function getOrgIdentities($coId, $identifier)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/org_identities.json?"
      . "coid=" . urlencode($coId) . "&"
      . "search.identifier=" . urlencode($identifier);
    $res = $this->http('GET', $url);
    assert('strncmp($res->{"ResponseType"}, "OrgIdentities", 13)===0');
    if (empty($res->{'OrgIdentities'})) {
      return array();
    }
    return $res->{'OrgIdentities'};
  }


  public function addCoPerson($coId, $status)
  {
    $url = $this->apiBaseURL . "/co_people.json";
    $req = '{'
      . '"RequestType":"CoPeople",'
      . '"Version":"1.0",'
      . '"CoPeople":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "CoId":"' . $coId . '",'
      . '     "Status":"' . $status . '"'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  // Response:
  // {
  //     "ResponseType":"CoPeople",
  //     "Version":"1.0",
  //     "CoPeople":
  //     [
  //         {
  //             "Version":"1.0",
  //             "Id":"<Id>",
  //             "CoId":"<CoId>",
  //             "Status":("Active"|"Approved"|"Confirmed"|"Declined"|"Deleted"|"Denied"|"Duplicate"|"Expired"|"GracePeriod"|"Invited"|"Pending"|"PendingApproval"|"PendingConfirmation"|"Suspended"),
  //             "Created":"<CreateTime>",
  //             "Modified":"<ModTime>"
  //         },
  //         {...}
  //     ]
  //   }
  public function getCoPerson($coPersonId)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/co_people/" . urlencode($coPersonId) . ".json";
    $data = $this->http('GET', $url);
    assert('strncmp($data->{"ResponseType"}, "CoPeople", 8)===0');
    if (empty($data->{'CoPeople'})) {
      return null;
    }
    return $data->{'CoPeople'}[0];
  }

  public function addCoOrgIdentityLink($coPersonId, $orgIdentityId)
  {
    $url = $this->apiBaseURL . "/co_org_identity_links.json";
    $req = '{'
      . '"RequestType":"CoOrgIdentityLinks",'
      . '"Version":"1.0",'
      . '"CoOrgIdentityLinks":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "CoPersonId":"' . $coPersonId . '",'
      . '     "OrgIdentityId":"' . $orgIdentityId . '"'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  // Response:
  // {
  //     "ResponseType":"CoOrgIdentityLinks",
  //     "Version":"1.0",
  //     "CoOrgIdentityLinks":
  //     [
  //         {
  //             "Version":"1.0",
  //             "Id":"<Id>",
  //             "CoPersonId":"<CoPersonId>",
  //             "OrgIdentityId":"<OrgIdentityId>",
  //             "Created":"<CreateTime>",
  //             "Modified":"<ModTime>"
  //         },
  //         {...}
  //     ]
  // }
  public function getCoOrgIdentityLinks($personType, $personId)
  {
    if (strncmp($personType, "CO", 2) === 0) {
      $personIdType = "copersonid";
    } elseif (strncmp($personType, "Org", 3) === 0) {
      $personIdType = "orgidentityid";
    } else {
      throw new InvalidArgumentException("$personType is not a valid personType");
    }

    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/co_org_identity_links.json?$personIdType="
      . urlencode($personId);
    $res = $this->http('GET', $url);
    //assert('strncmp($res->{"ResponseType"}, "CoOrgIdentityLinks", 18)===0'); --> Can't work for HTTP Status 204/404
    if (empty($res->{'CoOrgIdentityLinks'})) {
      return array();
    }
    return $res->{'CoOrgIdentityLinks'};
  }

  // Response:
  // {
  //     "ResponseType":"Identifiers",
  //     "Version":"1.0",
  //     "Identifiers":
  //     [
  //         {
  //             "Version":"1.0",
  //             "Id":"<ID>",
  //             "Type":"<Type>",
  //             "Identifier":"<Identifier>",
  //             "Login":true|false,
  //             "Person":{"Type":("CO"|"Org"),"ID":"<ID>"},
  //             "CoProvisioningTargetId":"<CoProvisioningTargetId>",
  //             "Status":"Active"|"Deleted",
  //             "Created":"<CreateTime>",
  //             "Modified":"<ModTime>"
  //         },
  //         {...}
  //     ]
  // }
  public function getIdentifiers($personType, $personId)
  {
    if (strncmp($personType, "CO", 2) === 0) {
      $personIdType = "copersonid";
    } elseif (strncmp($personType, "Org", 3) === 0) {
      $personIdType = "orgidentityid";
    } else {
      throw new InvalidArgumentException("$personType is not a valid personType");
    }

    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/identifiers.json?$personIdType="
      . urlencode($personId);
    $res = $this->http('GET', $url);
    assert('strncmp($res->{"ResponseType"}, "Identifiers", 11)===0');
    if (empty($res->{'Identifiers'})) {
      return array();
    }
    return $res->{'Identifiers'};
  }

  public function addIdentifier($type, $identifier, $login, $personType, $personId)
  {
    $url = $this->apiBaseURL . "/identifiers.json";
    $req = '{'
      . '"RequestType":"Identifiers",'
      . '"Version":"1.0",'
      . '"Identifiers":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Type":"' . $type . '",'
      . '     "Identifier":"' . $identifier . '",'
      . '     "Login":' . (($login) ? 'true' : 'false') . ','
      . '     "Person":'
      . '     {'
      . '       "Type":"' . $personType . '",'
      . '       "Id":"' . $personId . '"'
      . '     },'
      . '     "Status":"Active"'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  public function assignIdentifier($personId)
  {
    $url = $this->apiBaseURL . "/identifiers/assign.json";
    $req = '{'
      . '"RequestType":"Identifiers",'
      . '"Version":"1.0",'
      . '"Identifiers":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Person":'
      . '     {'
      . '       "Type":"CO",'
      . '       "Id":"' . $personId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  /*
   * Retrieves all existing Names.
   *
   * Response:
   * {
   *   "ResponseType":"Names",
   *   "Version":"1.0",
   *   "Names":
   *   [
   *     {
   *       "Version":"1.0",
   *       "Id":"<ID>",
   *       "Honorific":"<Honorific>",
   *       "Given":"<Given>",
   *       "Middle":"<Middle>",
   *       "Family":"<Family>",
   *       "Suffix":"<Suffix>",
   *       "Type":"<Type>",
   *       "Language":"<Language>",
   *       "PrimaryName":true|false,
   *       "Person":
   *       {
   *         "Type":("CO"|"Org"),
   *         "Id":"<ID>"
   *       }
   *       "Created":"<CreateTime>",
   *       "Modified":"<ModTime>"
   *     },
   *     {...}
   *   ]
   * }
   */
  public function getNames($personType, $personId)
  {
    if (strncmp($personType, "CO", 2) === 0) {
      $personIdType = "copersonid";
    } elseif (strncmp($personType, "Org", 3) === 0) {
      $personIdType = "orgidentityid";
    } else {
      throw new InvalidArgumentException("$personType is not a valid personType");
    }

    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/names.json?$personIdType="
      . urlencode($personId);
    $res = $this->http('GET', $url);
    //assert('strncmp($res->{"ResponseType"}, "Names", 5)===0');
    if (empty($res->{'Names'})) {
      return array();
    }
    return $res->{'Names'};
  }

  /*
   * Adds a new Name.
   */
  public function addName($given, $family, $type, $personType, $personId)
  {
    $url = $this->apiBaseURL . "/names.json";
    $req = '{'
      . '"RequestType":"Names",'
      . '"Version":"1.0",'
      . '"Names":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Given":"' . $given . '",'
      . '     "Family":"' . $family . '",'
      . '     "Type":"' . $type . '",'
      . '     "PrimaryName":true,'
      . '     "Person":'
      . '     {'
      . '       "Type":"' . $personType . '",'
      . '       "Id":"' . $personId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  /*
   * Updates an existing Name.
   */
  public function updateName($nameId, $given, $family, $type, $personType, $personId)
  {
    $url = $this->apiBaseURL . "/names/" . urlencode($nameId) .".json";
    $req = '{'
      . '"RequestType":"Names",'
      . '"Version":"1.0",'
      . '"Names":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Given":"' . $given . '",'
      . '     "Family":"' . $family . '",'
      . '     "Type":"' . $type . '",'
      . '     "PrimaryName":true,'
      . '     "Person":'
      . '     {'
      . '       "Type":"' . $personType . '",'
      . '       "Id":"' . $personId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('PUT', $url, $req);
    return $res;
  }

  /*
   * Retrieves all existing EmailAddresses.
   *
   * Response:
   * {
   *   "ResponseType":"EmailAddresses",
   *   "Version":"1.0",
   *   "EmailAddresses":
   *   [
   *     {
   *       "Version":"1.0",
   *       "Id":"<ID>",
   *       "Mail":"<Mail>",
   *       "Type":<"Type">,
   *       "Verified":true|false,
   *       "Person":
   *       {
   *         "Type":("CO"|"Org"),
   *         "Id":"<ID>"
   *       }
   *       "Created":"<CreateTime>",
   *       "Modified":"<ModTime>"
   *     },
   *     {...}
   *   ]
   * }
   */
  public function getEmailAddresses($personType, $personId)
  {
    if (strncmp($personType, "CO", 2) === 0) {
      $personIdType = "copersonid";
    } elseif (strncmp($personType, "Org", 3) === 0) {
      $personIdType = "orgidentityid";
    } else {
      throw new InvalidArgumentException("$personType is not a valid personType");
    }

    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/email_addresses.json?$personIdType="
      . urlencode($personId);
    $res = $this->http('GET', $url);
    //assert('strncmp($res->{"ResponseType"}, "EmailAddresses", 14)===0');
    if (empty($res->{'EmailAddresses'})) {
      return array();
    }
    return $res->{'EmailAddresses'};
  }

  /*
   * Adds a new EmailAddress.
   */
  public function addEmailAddress($mail, $type, $verified, $personType, $personId)
  {
    $url = $this->apiBaseURL . "/email_addresses.json";
    $req = '{'
      . '"RequestType":"EmailAddresses",'
      . '"Version":"1.0",'
      . '"EmailAddresses":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Mail":"' . $mail . '",'
      . '     "Type":"' . $type . '",'
      . '     "Verified":' . $verified . ','
      . '     "Person":'
      . '     {'
      . '       "Type":"' . $personType . '",'
      . '       "Id":"' . $personId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  /*
   * Updates an existing EmailAddress.
   */
  public function updateEmailAddress($emailId, $mail, $type, $verified, $personType, $personId)
  {
    $url = $this->apiBaseURL . "/email_addresses/" . urlencode($emailId) . ".json";
    $req = '{'
      . '"RequestType":"EmailAddresses",'
      . '"Version":"1.0",'
      . '"EmailAddresses":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "Mail":"' . $mail . '",'
      . '     "Type":"' . $type . '",'
      . '     "Verified":' . $verified . ','
      . '     "Person":'
      . '     {'
      . '       "Type":"' . $personType . '",'
      . '       "Id":"' . $personId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('PUT', $url, $req);
    return $res;
  }

  /**
   * '{'
   * . '"RequestType":"CoPersonRoles",'
   * . '"Version":"1.0",'
   * . '"CoPersonRoles":'
   * . '['
   * . '  {'
   * . '     "Version":"1.0",'
   * . '     "Person":'
   * . '     {'
   * . '       "Type":"CO",'
   * . '       "Id":"' . $coPersonId . '"'
   * . '     },'
   * . '     "CouId":"' . $couId . '",'
   * . '     "Affiliation":"' . $affiliation . '",'
   * . '     "Title":"' . $title . '",'
   * . '     "O":"' . $o . '",'
   * . '     "Ou":"' . $ou . '",'
   * . '     "Status":"' . $status . '",'
   * . '     "ValidFrom":"' . $validFrom . '",'
   * . '     "ValidThrough":"' . $validThrough . '"'
   * . '   }'
   * . ']'
   * . '}';
   * @param string $reqType
   * @param integer $coPersonId
   * @param integer $couId
   * @param string $status
   * @param string $affiliation
   * @param null $coPersonRoleId
   * @param string $title
   * @param string $validFrom
   * @param string $validThrough
   * @return mixed
   */
  public function CoPersonRole($reqType,
                               $coPersonId,
                               $couId,
                               $status,
                               $affiliation='member',
                               $coPersonRoleId=null,
                               $title='',
                               $validFrom='',
                               $validThrough='')
  {
    // TODO: Add duration constant for validFrom-validTrough
    // Always provide requestType
    if ( empty($reqType)) {
      return null;
    }
    // DELETE and GET only need the coPersonRoleID
    if (($reqType === 'POST' || $reqType === 'PUT')
         && (empty($couId) || empty($coPersonId) || empty($status))) {
      return null;
    }
    // Construct my data
    $reqDataArr = array();
    $reqDataArr['RequestType'] = 'CoPersonRoles';
    $reqDataArr['Version'] = '1.0';
    $reqDataArr['CoPersonRoles'][0]['Version'] = '1.0';
    $reqDataArr['CoPersonRoles'][0]['CouId'] = (string)($couId);
    $reqDataArr['CoPersonRoles'][0]['Affiliation'] = $affiliation;
    $reqDataArr['CoPersonRoles'][0]['Status'] = $status;
    //$reqDataArr['CoPersonRoles'][0]['ValidFrom'] = date("Y-m-d 00:00:00");
    //$reqDataArr['CoPersonRoles'][0]['ValidThrough'] = date("Y-m-d 00:00:00", strtotime(date("Y-m-d", strtotime($reqDataArr['CoPersonRoles'][0]['ValidFrom'])) . " + 1 year"));
    $reqDataArr['CoPersonRoles'][0]['Person']['Type'] = 'CO';
    $reqDataArr['CoPersonRoles'][0]['Person']['Id'] = (string)($coPersonId);
    if(!empty($title)) {
      $reqDataArr['CoPersonRoles'][0]['Title'] = $title;
    }


    $url = $this->apiBaseURL;
    $method = '';
    switch ($reqType) {
      case 'add':
        $url .= '/co_person_roles.json';
        $method = 'POST';
        $reqDataArr['CoPersonRoles'][0]['ValidFrom'] = $validFrom;
        $reqDataArr['CoPersonRoles'][0]['ValidThrough'] = $validThrough;
        $reqDataJson = json_encode($reqDataArr);
        break;
      case 'edit':
        if(empty($coPersonRoleId)) {
          return null;
        }
        $url .= '/co_person_roles/' . $coPersonRoleId . '.json';
        $reqDataJson = json_encode($reqDataArr);
        $method = 'PUT';
        break;
      case 'delete':
        if(empty($coPersonRoleId)) {
          return null;
        }
        $url .= '/co_person_roles/' . $coPersonRoleId . '.json';
        unset($reqDataArr);
        $method = 'DELETE';
        break;
      case 'view_one':
        $url .= '/co_person_roles/' . $coPersonRoleId . '.json';
        unset($reqDataArr);
        $method = 'GET';
        break;
      default:
        var_dump('Unknown Request action.');
    }

    $res = $this->http($method, $url, $reqDataJson);
    return $res;
  }

  public function addCoTAndCAgreement($coPersonId, $coTAndCId)
  {
    $url = $this->apiBaseURL . "/co_t_and_c_agreements.json";
    $req = '{'
      . '"RequestType":"CoTAndCAgreements",'
      . '"Version":"1.0",'
      . '"CoTAndCAgreements":'
      . '['
      . '  {'
      . '     "Version":"1.0",'
      . '     "CoTermsAndConditionsId":"' . $coTAndCId . '",'
      . '     "Person":'
      . '     {'
      . '       "Type":"CO",'
      . '       "Id":"' . $coPersonId . '"'
      . '     }'
      . '   }'
      . ']'
      . '}';
    $res = $this->http('POST', $url, $req);
    return $res;
  }

  private function _getOrgIdentities($identifier)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/org_identities.json?"
      // TODO Limit search to specific CO
      //. "coid=" . $this->_coId . "&"
      . "search.identifier=" . urlencode($identifier);
    $data = $this->http('GET', $url);
    assert('strncmp($data->{"ResponseType"}, "OrgIdentities", 13)===0');
    if (empty($data->{'OrgIdentities'})) {
      return array();
    }
    return $data->{'OrgIdentities'};
  }

  private function _getCoOrgIdentityLinks($orgIdentityId)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/co_org_identity_links.json?orgidentityid="
      . urlencode($orgIdentityId);
    $data = $this->http('GET', $url);
    assert('strncmp($data->{"ResponseType"}, "CoOrgIdentityLinks", 18)===0');
    if (empty($data->{'CoOrgIdentityLinks'})) {
      return array();
    }
    return $data->{'CoOrgIdentityLinks'};
  }

  private function _getCoGroups($coPersonId)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/co_groups.json?"
      . "copersonid=" . urlencode($coPersonId);
    $data = $this->http('GET', $url);
    assert('strncmp($data->{"ResponseType"}, "CoGroups", 8)===0');
    if (empty($data->{'CoGroups'})) {
      return array();
    }
    return $data->{'CoGroups'};
  }

  private function _getCo($coId)
  {
    // Construct COmanage REST API URL
    $url = $this->apiBaseURL . "/cos/"
      . urlencode($coId) . ".json";
    $data = $this->http('GET', $url);
    assert('strncmp($data->{"ResponseType"}, "Cos", 3)===0');
    if (empty($data->{'Cos'})) {
      return null;
    }
    return $data->{'Cos'}[0];
  }

  /**
   * @param string $method  Type of Http Request, POST,PUT,DELETE,GET
   * @param string $url     Endpoint to access
   * @param null $data      Body/Payload in json format
   * @return mixed
   */
  private function http($method, $url, $data = null)
  {
    $ch = curl_init($url);
    curl_setopt_array(
      $ch,
      array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $this->username . ":" . $this->password,
        CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
      )
    );
    if (($method === 'POST' || $method === 'PUT') && !empty($data)) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
          'Content-Length: ' . strlen($data))
      );
    }

    // Send the request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Check for error
    if ($http_code !== 200
        && $http_code !== 201
        && $http_code !== 204
        && $http_code !== 302
        && $http_code !== 404) {
      // Close session
      curl_close($ch);
      return null;
    }
    // Close session
    curl_close($ch);
    $result = json_decode($response, true);
    assert('json_last_error()===JSON_ERROR_NONE');
    if(empty($result)) {
      return $http_code;
    }
    return $result;
  }
}