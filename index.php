<?php
require_once('./common.php');
require_once('./configuration.php');
require_once('./utils/COmanageRestApi.php');

$db_host = $GLOBALS['db_host'];
$db_username = $GLOBALS['db_username'];
$db_password = $GLOBALS['db_password'];
$db_name = $GLOBALS['db_name'];

$saml_user_id = $GLOBALS['uid_key'];
$saml_user_name = $GLOBALS['username_key'];

# ======================
// Request Access to database
$db_conn = pg_connect("host={$db_host} dbname={$db_name} user={$db_username} password={$db_password}") or die('Could not connect: ' . pg_last_error());
// Create the COmanage Rest Client
$comanageClient = new COmanageRestApi($GLOBALS['api_base_url'], $GLOBALS['api_username'], $GLOBALS['api_password']);

# Find the CO Admins' emails
$availableCOsConcat = implode('|', $co_name);
$query_co_admins_Emails = <<<EOQ
select distinct cea.mail
from cm_email_addresses as cea
         inner join cm_co_people ccp on cea.co_person_id = ccp.id and
                                        not cea.deleted and cea.email_address_id is null and
                                        not ccp.deleted and ccp.co_person_id is null and
                                        ccp.status='A'
         inner join cm_co_group_members ccgm on ccp.id = ccgm.co_person_id and
                                        not ccgm.deleted and
                                        ccgm.co_group_member_id is null
         inner join cm_co_groups ccg on ccg.id = ccgm.co_group_id and
                                        not ccg.deleted and
                                        ccg.co_group_id is null and
                                        ccg.group_type = 'A'
         inner join cm_cos co on ccg.co_id = co.id
where co.name ~ '({$availableCOsConcat})'
  and co.status = 'A'
  and ccgm.member
  and ccg.name = 'CO:admins';
EOQ;



$userMgrEmailResult = pg_query($query_co_admins_Emails) or die('Query failed: ' . pg_last_error());
$coAdminsEmailList = array();
while($row = pg_fetch_row($userMgrEmailResult)) {
    $coAdminsEmailList[] = $row[0];
}

//handle POST roles first
if(!empty($_POST) && !empty($_POST['user_request'])) {
    $action = $_POST['user_request'];
    switch ($_POST['user_request']) {
        case 'apply':
            $personId = $_POST['person_id'];            // Request New Roles. Add COU with status PendingApproval
            // Remove coPersonId and user_request from post data in order to iterate through chosen roles
            unset($_POST['person_id'], $_POST['user_request']);

            $succeededCouAddRequests = array();
            foreach($_POST as $cou_name => $cou_id) {
                // Foreach replaces spaces with underscores. Let's revert it
                $cou_name = str_replace('_', ' ', $cou_name);
                $result = $comanageClient->CoPersonRole(
                    'add',
                    $personId,
                    $cou_id,
                    'PendingApproval',
                    'member'); // The request from the user should not include validFrom, validThrough. The admin should pick
                if(!empty($result) && !is_int($result)) {
                    $succeededCouAddRequests[] = $cou_name;
                }
            }
            if(!empty($succeededCouAddRequests)) {
                sendUserPostActionEmail('manager', $action, $succeededCouAddRequests, $coAdminsEmailList);
            }
            //clear remaining POST data
            unset($_POST);
            break;
        case 'restore': // Cancel the request for removing the role
            $personId = $_POST['person_id'];
            $coPersonRoleId = $_POST['role_id'];
            $couName = $_POST['cou_name'];
            $couId = $_POST['cou_id'];
            //clear POST variables
            unset($_POST);
            // Send data to COmanage
            $result = $comanageClient->CoPersonRole(
                'edit',
                $personId,
                $couId,
                'Active',
                'member',
                $coPersonRoleId);
            break;
        case 'cancel':  // Cancel Role Request by deleting the CoPersonRole entry from DB
            $roleId = $_POST['role_id'];
            //clear POST variables
            unset($_POST);
            // Send data to COmanage
            $result = $comanageClient->CoPersonRole(
                'delete',
                null,
                null,
                null,
                null,
                $roleId);
            break;
        case 'remove':  // Request Remove: Set the Role/COU to status Suspended by the USER
            $personId = $_POST['person_id'];
            $coPersonRoleId = $_POST['role_id'];
            $couName = $_POST['cou_name'];
            $couId = $_POST['cou_id'];
            //clear POST variables
            unset($_POST);
            // Send data to COmanage
            $result = $comanageClient->CoPersonRole(
                'edit',
                $personId,
                $couId,
                'Suspended',
                'member',
                $coPersonRoleId); // The request from the user should not include validFrom, validThrough.
            // Inform Manager
            if(!empty($result) && $result === 200) {
                sendUserPostActionEmail('manager', $action, array($couName), $coAdminsEmailList);
            }
            //sendRemoveRoleEmail($couName, 'manager');
            break;
        default:
            break;
    }
    // Die and redirect to this page
    header("Location: /roles/index.php"); /* Redirect browser */
    die();
}

# ALL ROLES
$regex = implode('|', $non_listed_roles);
$query_roles = <<<EOQ
SELECT cou.id as cou_id,
       cou.co_id as co_id,
       cou.name as cou_name
FROM cm_cous as cou
where not cou.deleted
  and cou.cou_id is null
  and cou.name !~ '^({$regex})'
ORDER BY cou.name
EOQ;
$result_roles = pg_query($query_roles) or die('Query failed: ' . pg_last_error());
$all_roles = pg_fetch_all($result_roles) ?: array();

// Get co_person_id from identifier
$query_coPersonId_from_sub = <<<EOQ
SELECT person.id
FROM cm_co_people person
         INNER JOIN cm_identifiers ident ON person.id = ident.co_person_id AND 
                                            not ident.deleted AND
                                            ident.identifier_id is null AND
                                            not person.deleted AND 
                                            person.co_person_id is null
WHERE ident.identifier = '{$_SERVER[$saml_user_id]}';
EOQ;

$result = pg_query($query_coPersonId_from_sub) or die('Query failed: ' . pg_last_error());
$coPersonData = pg_fetch_row($result);
if($coPersonData) {
    $coPersonId = $coPersonData[0];
} else {
    die('Could not find the corresponding coPersonId for user: '.$_SERVER[$saml_user_id].'<br />'.print_r(pg_fetch_all($result)));
}

// Get orgIdentity identifiers
$query_get_org_identifiers = <<<EOQ
select ident.identifier,
       ident.type
from cm_identifiers as ident
         inner join cm_org_identities coi on ident.org_identity_id = coi.id and
                                             not coi.deleted and
                                             coi.org_identity_id is null and
                                             not ident.deleted and
                                             ident.identifier_id is null and
                                             ident.co_person_id is null
         inner join cm_co_org_identity_links ccoil on coi.id = ccoil.org_identity_id and
                                                      not ccoil.deleted and
                                                      ccoil.co_org_identity_link_id is null
         inner join cm_co_people ccp on ccoil.co_person_id = ccp.id and
                                        not ccp.deleted and
                                        ccp.co_person_id is null
WHERE ident.status = 'A' and
      ccp.id = {$coPersonId};
EOQ;
$identOrgResult = pg_query($query_get_org_identifiers) or die('Query failed: ' . pg_last_error());
$orgIdentifiers = array();
while($row = pg_fetch_row($identOrgResult)) {
    $orgIdentifiers[$row[1]] = $row[0];
}

// Get CO Person linked identifiers
$query_get_ccp_identifiers = <<<EOQ
select ident.identifier,
       ident.type
from cm_identifiers as ident
         inner join cm_co_people ccp on ident.co_person_id = ccp.id and
                                        not ccp.deleted and
                                        ccp.co_person_id is null and
                                        not ident.deleted and ident.identifier_id is null
WHERE ccp.id ={$coPersonId};
EOQ;
$identCcpResult = pg_query($query_get_ccp_identifiers) or die('Query failed: ' . pg_last_error());
$ccpIdentifiers = array();
while($row = pg_fetch_row($identCcpResult)) {
    $ccpIdentifiers[$row[1]] = $row[0];
}

// Get both status Active and Suspended since the suspended ones are those that can be canceled by the user
$couQuery = <<<EOQ
SELECT DISTINCT (cou.name) as cou_name,
                cou.id as cou_id,
                role.id as role_id,
                role.status as role_status
FROM cm_cous AS cou
         INNER JOIN cm_co_person_roles AS role ON cou.id = role.cou_id and
                                                  not cou.deleted and
                                                  cou.cou_id is null
WHERE role.co_person_id = '{$coPersonId}'
  AND role.co_person_role_id IS NULL
  AND role.affiliation = 'member'
  AND role.status IN ('A', 'S')
  AND NOT role.deleted
ORDER BY cou.name DESC;
EOQ;

$couResult = pg_query($couQuery) or die('Query failed: ' . pg_last_error());
$current_roles = pg_fetch_all($couResult) ?: array();

// PA: Stands for pendingApproval in the Registry
$petitionsQuery = <<<EOQ
SELECT role.id           AS role_id,
       role.status       as status,
       role.co_person_id AS person_id,
       role.cou_id       as cou_id,
       cou.name          AS cou_name
FROM cm_co_person_roles as role
         INNER JOIN cm_cous AS cou on cou.id = role.cou_id and
                                      not role.deleted and
                                      not cou.deleted and
                                      role.co_person_role_id is null and
                                      cou.cou_id is null
WHERE role.co_person_id = '{$coPersonId}' and
      role.status = 'PA';
EOQ;

$petitionsResult = pg_query($petitionsQuery) or die('Query failed: ' . pg_last_error());
$petitionFetch = pg_fetch_all($petitionsResult) ?: array();

// S: Stands for Suspended
$retiredPetitionsQuery = <<<EOQ
SELECT role.id AS id,
       role.status as status,
       role.co_person_id AS person_id,
       role.cou_id as cou_id,
       cou.name AS cou_name
FROM cm_co_person_roles as role
         INNER JOIN cm_cous AS cou on cou.id = role.cou_id and
                                      not role.deleted and
                                      not cou.deleted and
                                      role.co_person_role_id is null and
                                      cou.cou_id is null
WHERE role.co_person_id = {$coPersonId} and
      role.status='S';
EOQ;

$retiredPetitionsResult = pg_query($retiredPetitionsQuery) or die('Query failed: ' . pg_last_error());
$retiredPetitionsFetch = pg_fetch_all($retiredPetitionsResult) ?: array();

$retiredPetitions = Array();
foreach($retiredPetitionsFetch as $role) {
    array_push($retiredPetitions, $role['cou_name']);
}

foreach($current_roles as $key => $role) {
    if($role['cou_name'] == 'Registered User') {
        $foundRegisteredUser = true;
    }
    if(!in_array($role['cou_name'], $GLOBALS['non_removable_roles'], true)) {
        $current_roles[$key]['removable'] = true;
    }
}

// TODO: This should be removed from here
//if (!$foundRegisteredUser) {
//    $current_roles[] = array('id' => 19, 'name' => 'Registered User');
//}

$petition_roles = pg_fetch_all($petitionsResult) ?: array();

# Find if the current user is a CO Admin
# view this page
$isCoAdmin = false;
$availableCOsConcat = implode('|', $co_name);
$query_admin_roles_coPersonId = <<<EOQ
select ccgm.co_person_id
from cm_co_group_members as ccgm
         inner join cm_co_groups ccg on ccg.id = ccgm.co_group_id and
                                        not ccgm.deleted and not ccg.deleted and
                                        ccgm.co_group_member_id is null and
                                        ccg.co_group_id is null and
                                        ccg.group_type = 'A'
         inner join cm_co_people ccp on ccgm.co_person_id = ccp.id and
                                        not ccp.deleted and ccp.co_person_id is null and
                                        ccp.status='A'
         inner join cm_identifiers ci on ccp.id = ci.co_person_id and
                                         not ci.deleted and
                                         ci.identifier_id is null
         inner join cm_cos co on ccg.co_id = co.id
where co.name ~ '({$availableCOsConcat})'
  and co.status = 'A'
  and ccgm.member
  and ccg.name = 'CO:admins'
  and ci.identifier = '{$_SERVER[$saml_user_id]}';
EOQ;
$coPersonIdResult = pg_query($query_admin_roles_coPersonId) or die('Query failed: ' . pg_last_error());
$coPersonData = pg_fetch_row($coPersonIdResult);
// If no $coPersonData retrieved then redirect to index.php view
$isCoAdmin = !empty($coPersonData);

// Available Roles are all the roles minus the Current and Pending
$available_roles = array();
// Remove from all Roles List the ones i am currently a member
if($current_roles) {
    $available_roles = array_filter($all_roles, function($needle) use ($current_roles) {
        foreach($current_roles as $item) {
            if($item['cou_id'] === $needle['cou_id']) {
                return 0;
            }
        }
        return 1;
    });
}
// Remove from all Roles List the ones i am pending confirmation
if($petition_roles) {
    $available_roles = array_filter($available_roles, function($needle) use ($petition_roles) {
        foreach($petition_roles as $item) {
            if($item['cou_id'] === $needle['cou_id']) {
                return 0;
            }
        }
        return 1;
    });
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <title>Roles: Edit <?php echo $_SERVER[$saml_user_name] ?></title>

  <link rel="stylesheet" type="text/css" href="./resources/styles/bootstrap.min.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/app.min.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/theme.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/loader.css">
</head>
<body>

<!-- imported header -->
<div class="header">
  <div class="text-center ssp-logo">
    <a href="https://www.openaire.eu/">
      <img src="./resources/images/logo_horizontal.png" alt="OpenAIRE" />
    </a>
  </div>
</div> <!-- /header -->

<div class=" ssp-container js-spread" id="content">
  <div class="container-fluid">
    <div class="row justify-content-md-center">
      <div class="col-lg-6 col-md-8 col-sm-12">

        <h2 class="subtle text-center"><?php echo $_SERVER[$saml_user_name] ?> Profile</h2>
          <?php if ($db_conn === FALSE) : ?>
            <p>Connection failed.</p>
            <p><?php if ($DEBUG) echo(pg_last_error()); ?></p>

          <?php else : ?>
        <div class="table-header">
          <div>Identifiers</div>
        </div>
        <table class="table ssp-table">
          <tbody>
          <?php $index = 0; ?>
          <?php foreach ($ccpIdentifiers as $type => $ident) : ?>
              <?php if($index % 2 === 0) : ?>
              <tr class="ssp-table--tr__odd">
              <?php else : ?>
              <tr class="ssp-table--tr__even">
              <?php endif; ?>
            <td><?php print $ident; ?></td>
            <td class="text-center"></td>
            </tr>
              <?php $index++; ?>
          <?php endforeach; ?>
          <?php foreach ($orgIdentifiers as $type => $ident) : ?>
              <?php if($type === 'orcid') : ?>
                  <?php if($index % 2 === 0) : ?>
                <tr class="ssp-table--tr__odd">
                  <?php else : ?>
                <tr class="ssp-table--tr__even">
                  <?php endif; ?>
              <td><a href="<?php print $ident;?>"><?php print $ident;?></a></td>
                  <?php $index++; ?>
              <td class="text-center"></td>
              </tr>
              <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>

        <div class="table-header">
          Current Roles (<?php echo count($current_roles); ?>)
        </div>
        <table class="table ssp-table">
          <tbody>
          <?php $current_roles_index = 0; ?>
          <?php foreach ($current_roles as $key => $value) : ?>
              <?php if($current_roles_index % 2 === 0) : ?>
              <tr class="ssp-table--tr__odd">
              <?php else : ?>
              <tr class="ssp-table--tr__even">
              <?php endif; ?>
            <td><?php echo $value['cou_name'] ?></td>
            <td class="button">
                <?php if($value['removable'] && $value['role_status'] === 'A'): ?>
                  <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="person_id" value="<?php echo $coPersonId ?>" />
                    <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>" />
                    <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>" />
                    <input type="hidden" name="cou_id" value="<?php echo $value['cou_id'] ?>" />
                    <input type="hidden" name="user_request" value="remove" />
                    <input class="ssp-btn ssp-btn__secondary ssp-btn__red btn ssp-btns-container--btn__right text-uppercase" type="submit" value="Remove Role">
                  </form>
                <?php endif; ?>
                <?php if($value['role_status'] === 'S'): ?>
                  <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="person_id" value="<?php echo $coPersonId ?>" />
                    <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>" />
                    <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>" />
                    <input type="hidden" name="cou_id" value="<?php echo $value['cou_id'] ?>" />
                    <input type="hidden" name="user_request" value="restore" />
                    <input class="ssp-btn ssp-btn__secondary ssp-btn__red btn ssp-btns-container--btn__right text-uppercase" type="submit" value="Cancel Remove Role">
                  </form>
                <?php endif; ?>
            </td>
            </tr>
              <?php $current_roles_index++; ?>
          <?php endforeach; ?>
          <?php pg_free_result($couResult); ?>
          </tbody>
        </table>

        <div class="table-header">
            <?php $petition_length = ($petition_roles) ? count($petition_roles) : 0; ?>
          Pending Roles (<?php echo $petition_length; ?>)
        </div>
        <table class="table ssp-table">
          <tbody>
          <?php $petition_roles_index = 0; ?>
          <?php foreach ($petition_roles as $key => $value) : ?>
              <?php if($petition_roles_index % 2 === 0) : ?>
              <tr class="ssp-table--tr__odd">
              <?php else : ?>
              <tr class="ssp-table--tr__even">
              <?php endif; ?>
            <td><?php echo $value['cou_name'] ?></td>
            <td class="button">
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>" />
                <input type="hidden" name="user_request" value="cancel" />
                <input class="ssp-btn ssp-btn__secondary ssp-btn__red btn ssp-btns-container--btn__right text-uppercase" type="submit" value="Cancel">
              </form>
            </td>
            </tr>
              <?php $petition_roles_index++; ?>
          <?php endforeach; ?>
          <?php pg_free_result($couResult); ?>
          </tbody>
        </table>

        <div class="table-header">
          Available Roles (<?php echo count($available_roles); ?>)
        </div>
        <form method="POST" onsubmit="return handleFormSubmit()" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
          <input type="hidden" name="person_id" value="<?php echo $coPersonId ?>" />
          <input type="hidden" name="user_request" value="apply" />
          <div class="table-responsive" style="overflow-y: scroll; height: 300px;">
            <table id="new-roles" class="table ssp-table scrollbox">
                <?php $available_roles_index = 0; ?>
                <?php foreach ($available_roles as $key => $value) : ?>
                    <?php if($available_roles_index % 2 === 0) : ?>
                    <tr class="ssp-table--tr__odd">
                    <?php else : ?>
                    <tr class="ssp-table--tr__even">
                    <?php endif; ?>
                  <td>
                    <div class="form-check">
                      <label class="form-check-label">
                        <input onClick="getCheckboxState()"
                               class="form-control"
                               type="checkbox"
                               name="<?php echo $value['cou_name']; ?>"
                               value="<?php echo $value['cou_id']; ?>"
                               id="role<?php echo $value['cou_id']; ?>">
                          <?php echo $value['cou_name'] ?>
                      </label>
                    </div>
                  </td>
                  </tr>
                    <?php $available_roles_index++; ?>
                <?php endforeach; ?>
                <?php pg_free_result($couResult); ?>
            </table>
          </div>
          <div class="text-center form-submit-container">
            <input id="submitRoles" class="ssp-btn btn ssp-btn__action ssp-btns-container--btn__left text-uppercase" type="submit" value="Submit" />
          </div>
        </form>
          <?php if($isCoAdmin) :?>
            <div class="text-center">
              <a class="btn btn-primary greenish" href="/roles/admin.php">Go to admin area</a>
            </div>
          <?php endif; ?>
      </div>
    </div>
      <?php endif; // connection_ok ?>

  </div>
</div>
<!-- Spinner element -->
<div class="lds-spinner" style="display: none;">
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
  <div></div>
</div>

<!-- imported footer -->
<footer class="ssp-footer text-center">
  <div class="container-fluid ssp-footer--container">
    <div class="copy">Powered by <a href="https://github.com/rciam">RCIAM</a> | Service provided by <a href="https://grnet.gr/">GRNET</a></div>
  </div>
</footer>

<?php // content ?>
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha384-xBuQ/xzmlsLoJpyjoggmTEz8OWUFM0/RC5BsqQBDX2v5cMvDHcMakNTNrHIW2I5f" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.3/umd/popper.min.js" integrity="sha384-vFJXuSJphROIrBnz7yo7oB41mKfc8JzQZiCq4NCceLEaO4IHwicKwpJf9c9IpFgh" crossorigin="anonymous"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
<script>
    var boxesChecked = false;
    document.getElementById("submitRoles").classList.add('disabled')

    function getCheckboxState() {
        boxesChecked = false;
        var boxes = document.querySelectorAll("#new-roles input");
        boxes.forEach(function(checkbox) {
            if(checkbox.checked) {
                boxesChecked = true;
            }
        });
        if(boxesChecked) {
            document.getElementById("submitRoles").classList.remove('disabled');
        } else {
            document.getElementById("submitRoles").classList.add('disabled')
        }
    }

    function handleFormSubmit() {
        return boxesChecked;
    }

    $(document).ready(function () {
        $("input[type='submit']").on('click', function () {
            $(".lds-spinner").show();
        });
    });
</script>
</body>
</html>
<?php pg_close($db_conn); ?>
