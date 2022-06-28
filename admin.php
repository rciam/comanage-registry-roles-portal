<?php
// Start the session
session_start();
require_once('./common.php');
require_once('./configuration.php');
require_once('./utils/COmanageRestApi.php');

$DEBUG = TRUE;

$db_host = $GLOBALS['db_host'];
$db_username = $GLOBALS['db_username'];
$db_password = $GLOBALS['db_password'];
$db_name = $GLOBALS['db_name'];

$saml_user_id = $GLOBALS['uid_key'];

# ======================
// Request Access to database
$db_conn = pg_connect("host={$db_host} dbname={$db_name} user={$db_username} password={$db_password}") or die('Could not connect: ' . pg_last_error());
// Create the COmanage Rest Client
$comanageClient = new COmanageRestApi($GLOBALS['api_base_url'], $GLOBALS['api_username'], $GLOBALS['api_password']);
# ======================

#handle POST request, if available
if(!empty($_POST) && !empty($_POST['role_status'])) {
  // Get the email so that we can inform the user
  $email = $_POST['email'];
  $action = $_POST['role_status'];
  switch($_POST['role_status']) {
    case 'accepted':
      $couName = $_POST['cou_name'];
      $mailBody = 'Your request to obtain the role ' . $couName . ' has been accepted.';
    case 'restored':
      // Update Users Role
      $couName = $_POST['cou_name'];
      if(empty($mailBody)) {
        $mailBody = 'Your request to leave role ' . $couName . ' has been rejected.';
      }
      $personId = $_POST['co_person_id'];
      $coPersonRoleId = $_POST['role_id'];
      $couId = $_POST['cou_id'];
      $mail = $_POST['email'];
      //clear POST variables
      unset($_POST);
      // Send data to COmanage
      $result = $comanageClient->CoPersonRole('edit', $personId, $couId, 'Active', 'member', $coPersonRoleId); // The request from the user should not include validFrom, validThrough.
      $status_flag = true;
      if((is_int($result) && $result === 200) || !empty($result)) {
        $msg = 'Role Activated';
        $type = 'success';
      } else if(empty($result)) {
        $msg = 'Role Activation Failed';
        $type = 'error';
      }
      break;
    case 'rejected':
      $couName = $_POST['cou_name'];
      $mailBody = 'Your request for role ' . $couName . ' has been rejected.';
    case 'removed':
      $couName = $_POST['cou_name'];
      if(empty($mailBody)) {
        $mailBody = 'Your request to leave role ' . $couName . ' has been accepted.';
      }
      // Update Users Role
      $coPersonRoleId = $_POST['role_id'];
      $mail = $_POST['email'];
      //clear POST variables
      unset($_POST);
      // Send data to COmanage
      $result = $comanageClient->CoPersonRole('delete', null, null, null, null, $coPersonRoleId);
      if(is_int($result)
        && ($result === 200 || $result === 204)) {
        $msg = 'Role Removed';
        $type = 'success';
      } else {
        $msg = 'Role Remove Failed';
        $type = 'error';
      }
      $status_flag = false;
      break;
    default:
      // Add message into Session
      $msg = 'Unavailable Request Action.';
      $type = 'warning';
      break;
  }

  $_SESSION['notify']['msg'] = $msg;
  $_SESSION['notify']['type'] = $type;
  // Send the email only the request succeeded
  if($_SESSION['notify']['type'] === 'success') {
    sendAdminPostActionEmail($mail, $couName, $action, $mailBody);
  }
  header('Location: /roles/admin.php'); /* Redirect browser */
  // Always die after redirect
  die();
}

// Used for notification messages
if(!empty($_SESSION['notify'])) {
  $notify_msg = $_SESSION['notify']['msg'];
  $notify_type = $_SESSION['notify']['type'];
  // Reset message
  unset($_SESSION['notify']);
}

# ======================
# Find if the current user is a CO Admin
# view this page

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
if(empty($coPersonData)) {
  header("Location: /roles/index.php"); /* Redirect browser */
  exit();
}

// Get all petition with status
// 'PA', Pending Approval
// 'S',  Suspended, Pending Removal
$petitionsQuery = <<<EOQ
select distinct * from (
         SELECT name.given                                                         as given,
                name.family                                                        as family,
                cea.mail                                                           as mail,
                cou.name                                                           as cou_name,
                ccpr.status                                                        as status,
                ccpr.co_person_id                                                  as person_id,
                ccpr.cou_id                                                        as cou_id,
                ccpr.created                                                       as created,
                ccpr.id                                                            as role_id,
                string_agg(ci.identifier || '(' || ci.type || ')', ',') over (partition by ci.co_person_id) as identifier
         FROM cm_co_person_roles AS ccpr
                  INNER JOIN cm_cous AS cou ON cou.id = ccpr.cou_id and
                                               not cou.deleted and not ccpr.deleted and
                                               cou.cou_id is null and
                                               ccpr.co_person_role_id is null
                  INNER JOIN cm_names AS name ON ccpr.co_person_id = name.co_person_id and
                                                 not name.deleted and name.name_id is null and
                                                 name.primary_name is true
                  INNER JOIN (
             select co_person_id,
                    min(id) as id
             from cm_email_addresses
             where not deleted
               and email_address_id is null
               and co_person_id is not null
             GROUP BY co_person_id
         ) email_win ON ccpr.co_person_id = email_win.co_person_id
                  INNER JOIN cm_email_addresses as cea on cea.id = email_win.id
                  INNER JOIN cm_identifiers ci on ccpr.co_person_id = ci.co_person_id and
                                                  not ci.deleted and
                                                  ci.identifier_id is null and
                                                  ci.status = 'A'
         WHERE ccpr.status in ('PA', 'S')
     ) profile;
EOQ;

$petitionsResult = pg_query($petitionsQuery) or die('Query failed: ' . pg_last_error());
$petitions = pg_fetch_all($petitionsResult);
// Filter Suspended
$petitionsSuspended = array_filter($petitions, function($row) {
  return $row['status'] === 'S';
});
// Reset the indexes
$petitionsSuspended = array_values($petitionsSuspended);
// Filter Pending Approval
$petitionsPendingApproval = array_filter($petitions, function($row) {
  return $row['status'] === 'PA';
});
// Reset the indexes
$petitionsPendingApproval = array_values($petitionsPendingApproval);
// Release Resources
pg_free_result($petitionsResult);
pg_close($db_conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <title>Role Management</title>

  <link rel="stylesheet" type="text/css" href="./resources/styles/bootstrap.min.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/jquery.dataTables.min.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/app.min.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/notify.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/prettify.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/loader.css">
  <link rel="stylesheet" type="text/css" href="./resources/styles/font-awesome.min.css">
</head>
<body>

<!-- imported header -->
<div class="header">
  <div class="text-center ssp-logo">
    <a href="https://www.openaire.eu/">
      <img src="./resources/images/logo_horizontal.png" alt="OpenAIRE"/>
    </a>
  </div>
</div> <!-- /header -->

<div class=" ssp-container js-spread" id="content">
  <div class="container-fluid">
    <div class="row justify-content-md-center">
      <div class="col-lg-8 col-md-10 col-sm-12">

        <h2 class="text-center">Welcome to the Role Management App</h2><br/><br/>
        <?php if($db_conn == FALSE) : ?>
          <p>Connection failed.</p>
          <p><? if($DEBUG) echo(pg_last_error()); ?></p>
        <?php else : ?>
          <?php
          $num_pending_approval = count($petitionsPendingApproval);
          $num_pending_suspension = count($petitionsSuspended);
          ?>
          <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" href="#assign-roles" data-toggle="tab" aria-selected="true" role="tab">Assign
                New Roles
                <?php if($num_pending_approval < 1): ?>
                <span class="badge badge-pill badge-success">
                  <?php else : ?>
                    <span class="badge badge-pill badge-danger">
                  <?php endif ?>
                  <?php echo $num_pending_approval; ?>
                  </span>
              </a></li>
            <li class="nav-item">
              <a class="nav-link" href="#revoke-roles" data-toggle="tab" aria-selected="false" role="tab">Revoke Granted
                Roles
                <?php if($num_pending_suspension < 1): ?>
                <span class="badge badge-pill badge-success">
                  <?php else : ?>
                    <span class="badge badge-pill badge-danger">
                  <?php endif ?>
                  <?php echo $num_pending_suspension; ?>
                  </span>
              </a></li>
          </ul>
          <div class="tab-content ">
            <div class="tab-pane fade show active" role="tabpanel" id="assign-roles"><br/>
              <table id="assign-roles-tbl" style="width: 100%">
                <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Name</th>
<!--                  <th scope="col">Identifiers</th>-->
                  <th scope="col">Email</th>
                  <th scope="col">Role</th>
                  <th scope="col">Created</th>
                  <th scope="col" class="text-center">Accept</th>
                  <th scope="col" class="text-center">Reject</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($petitionsPendingApproval as $key => $value) : ?>
                  <tr>
                    <td scope="row"><?php echo $key + 1 ?></td>
                    <td><a href="<?php echo $GLOBALS['api_base_url']; ?>/co_people/canvas/<?php echo $value['person_id'] ?>"><?php echo $value['given'] . " " . $value['family'] ?></a></td>
<!--                    <td>-->
<!--                        --><?php
//                        $identifiers = explode(',', $value['identifier']);
//                        echo "<ul>";
//                        foreach ($identifiers as $ident) {
//                            if (filter_var($ident, FILTER_VALIDATE_URL)) {
//                                $re = '/(.*)([(].*[)])/m';
//                                $ident_href = preg_replace($re, '$1', $ident);
//                                echo "<li><a href='" . $ident_href . "'>" . $ident . "</a></li>";
//                            } else {
//                                echo "<li>" . $ident . "</li>";
//                            }
//                        }
//                        echo "</ul>";
//                        ?>
<!--                    </td>-->
                    <td><?php echo $value['mail'] ?></td>
                    <td><?php echo $value['cou_name'] ?></td>
                    <td><?php echo $value['created'] ?></td>
                    <td class="text-center">
                      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <input type="hidden" name="co_person_id" value="<?php echo $value['person_id'] ?>"/>
                        <input type="hidden" name="cou_id" value="<?php echo $value['cou_id'] ?>"/>
                        <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>"/>
                        <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>"/>
                        <input type="hidden" name="email" value="<?php echo $value['mail'] ?>"/>
                        <input type="hidden" name="role_status" value="accepted"/>
                        <input class="btn btn-success text-uppercase" type="submit" value="Accept">
                      </form>
                    </td>
                    <td class="text-center">
                      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>"/>
                        <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>"/>
                        <input type="hidden" name="email" value="<?php echo $value['mail'] ?>"/>
                        <input type="hidden" name="role_status" value="rejected"/>
                        <input class="btn btn-danger text-uppercase" type="submit" value="Reject">
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="tab-pane fade" role="tabpanel" id="revoke-roles"><br/>
              <table id="revoke-roles-tbl" style="width: 100%">
                <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Name</th>
<!--                  <th scope="col">Identifiers</th>-->
                  <th scope="col">Email</th>
                  <th scope="col">Role</th>
                  <th scope="col">Created</th>
                  <th scope="col" class="text-center">Accept</th>
                  <th scope="col" class="text-center">Reject</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($petitionsSuspended as $key => $value) : ?>
                  <tr>
                    <th scope="row"><?php echo $key + 1 ?></th>
                    <td><a href="<?php echo $GLOBALS['api_base_url']; ?>/co_people/canvas/<?php echo $value['person_id'] ?>"><?php echo $value['given'] . " " . $value['family'] ?></a></td>
<!--                    <td>-->
<!--                        --><?php
//                        $identifiers = explode(',', $value['identifier']);
//                        echo "<ul>";
//                        foreach ($identifiers as $ident) {
//                            if (filter_var($ident, FILTER_VALIDATE_URL)) {
//                                $re = '/(.*)([(].*[)])/m';
//                                $ident_href = preg_replace($re, '$1', $ident);
//                                echo "<li><a href='" . $ident_href . "'>" . $ident . "</a></li>";
//                            } else {
//                                echo "<li>" . $ident . "</li>";
//                            }
//                        }
//                        echo "</ul>";
//                        ?>
<!--                    </td>-->
                    <td><?php echo $value['mail'] ?></td>
                    <td><?php echo $value['cou_name'] ?></td>
                    <td><?php echo $value['created'] ?></td>
                    <td class="text-center">
                      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>"/>
                        <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>"/>
                        <input type="hidden" name="email" value="<?php echo $value['mail'] ?>"/>
                        <input type="hidden" name="role_status" value="removed"/>
                        <input class="btn btn-success text-uppercase" type="submit" value="Accept">
                      </form>
                    </td>
                    <td class="text-center">
                      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <input type="hidden" name="co_person_id" value="<?php echo $value['person_id'] ?>"/>
                        <input type="hidden" name="cou_id" value="<?php echo $value['cou_id'] ?>"/>
                        <input type="hidden" name="role_id" value="<?php echo $value['role_id'] ?>"/>
                        <input type="hidden" name="cou_name" value="<?php echo $value['cou_name'] ?>"/>
                        <input type="hidden" name="email" value="<?php echo $value['mail'] ?>"/>
                        <input type="hidden" name="role_status" value="restored"/>
                        <input class="btn btn-danger text-uppercase" type="submit" value="Reject">
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; // connection_ok ?>

      </div>
    </div>
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
    <div class="copy">Powered by <a href="https://github.com/rciam">RCIAM</a> | Service provided by <a
        href="https://grnet.gr/">GRNET</a></div>
  </div>
</footer>

<?php // content ?>
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="./resources/js/jquery-3.2.1.min.js"></script>
<script src="./resources/js/popper.min.js"></script>
<script src="./resources/js/bootstrap.min.js"></script>
<script src="./resources/js/jquery.dataTables.min.js"></script>
<script src="./resources/js/jquery-ui.min.js"></script>
<script src="./resources/js/app.js"></script>
<script src="./resources/js/notify.js"></script>
<script src="./resources/js/prettify.js"></script>
</body>
</html>

<script type="text/javascript">
    $(document).ready(function () {
        var notify_type = "<?php print $notify_type; ?>";
        var notify_msg = "<?php print $notify_msg; ?>";

        if (notify_msg !== '' && notify_type !== '') {
            $.notify(notify_msg, {
                type: notify_type,
                blur: 0.2,
                delay: 0
            });
        }

        $("input[type='submit']").on('click', function () {
            $(".lds-spinner").show();
        });
    });
</script>
