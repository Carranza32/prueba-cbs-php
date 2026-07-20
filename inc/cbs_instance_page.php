<?php
global $wpdb;
  $table_name = 'cbs_instances';
  if (isset($_POST['newsubmit'])) {
    $instance_name_new = $_POST['instance_name_new'];
    $wpdb->query("INSERT INTO $table_name(instance_name) VALUES('$instance_name_new')");
    echo "<script>location.replace('admin.php?page=general-settings');</script>";
  }
  if (isset($_POST['uptsubmit'])) {
    $id = $_POST['uptid'];
    $instance_name = $_POST['instance_name'];
    $wpdb->query("UPDATE $table_name SET instance_name='$instance_name' WHERE id=$id");
    echo "<script>location.replace('admin.php?page=general-settings');</script>";
  }
  if (isset($_GET['del'])) {
    $del_id = $_GET['del'];
    $wpdb->query("DELETE FROM $table_name WHERE id='$del_id'");
    echo "<script>location.replace('admin.php?page=general-settings');</script>";
  }
  ?>
  <div class="wrap">
    <h2>CRUD Operations</h2>
    <table class="wp-list-table widefat striped">
      <thead>
        <tr>
          <th width="25%">Name</th>
          <th width="25%">Actions</th>
        </tr>
      </thead>
      <tbody>
        <form action="" method="post">
          <tr>
            <td><input type="text" id="instance_name_new" name="instance_name_new"></td>
            <td><button id="newsubmit" name="newsubmit" type="submit">INSERT</button></td>
          </tr>
        </form>
        <?php
          $result = $wpdb->get_results("SELECT * FROM $table_name");
          foreach ($result as $print) {
            echo "
              <tr>
                <td width='25%'>$print->instance_name</td>
                <td width='25%'><a href='admin.php?page=general-settings&upt=$print->id'><button type='button'>UPDATE</button></a> <a href='admin.php?page=general-settings&del=$print->id'><button type='button'>DELETE</button></a></td>
              </tr>
            ";
          }
        ?>
      </tbody>  
    </table>
    <br>
    <br>
    <?php
      if (isset($_GET['upt'])) {
        $upt_id = $_GET['upt'];
        $result = $wpdb->get_results("SELECT * FROM $table_name WHERE id='$upt_id'");
        foreach($result as $print) {
          $name = $print->instance_name;
        }
        echo "
        <table class='wp-list-table widefat striped'>
          <thead>
            <tr>
              <th width='25%'>Name</th>
              <th width='25%'>Actions</th>
            </tr>
          </thead>
          <tbody>
            <form action='' method='post'>
              <tr>
                <input type='hidden' id='uptid' name='uptid' value='$print->id'>
                <td width='25%'><input type='text' id='instance_name' name='instance_name' value='$print->name'></td>
                <td width='25%'><button id='uptsubmit' name='uptsubmit' type='submit'>UPDATE</button> <a href='admin.php?page=general-settings'><button type='button'>CANCEL</button></a></td>
              </tr>
            </form>
          </tbody>
        </table>";
      }
    ?>
  </div>