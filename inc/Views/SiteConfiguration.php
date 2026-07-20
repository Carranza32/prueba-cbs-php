<?php
namespace CBSNorthStar\Views;

use  CBSNorthStar\Repositories\ConfigurationRepository;
use  CBSNorthStar\Models\Sites;

class SiteConfiguration{

    public function render(){
        $test =  "";
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tokenData = $_POST['token'];
        }
        $tokenId = ConfigurationRepository::create()->getDetails()->id;
        $siteDetails = ConfigurationRepository::create()->getSitesDetails($tokenId);

        ob_start();
        ?>
            <form action="" method="post">
                <?php echo $tokenData; ?>
                <?php echo $test; ?>
                <?php echo $this->tokenForm();?>
                <?php /* print("<pre>".print_r($siteDetails,true)."</pre>"); */ ?>
                <?php /* print("<pre>". print_r((new Sites())->load("ccdcdcddcdrfrgr") ,true)."</pre>"); */ ?>
                <?php echo $this->sitesTable(((new Sites())->load("ccdcdcddcdrfrgr"))['sites']) ?>
            </form>
        <?php
        return ob_get_clean();
    }
    public function tokenForm(): string{
        $instanceRecord= array();
        $instances = ConfigurationRepository::create()->getInstances();
        $activeInstance = ConfigurationRepository::create()->getDetails()->instance;
        $activeToken = ConfigurationRepository::create()->getDetails()->token;
        
        if(!empty($instances))  {
          foreach($instances as $inst){
                $instanceRecord[$inst->instance_name] = $inst->instance_oeapiurl;
            }
        }
        ob_start();
        ?>
            <label for="token">Access Token:</label>
            <input type="text" id="token" name="token" value="<?php echo $activeToken ? $activeToken : "" ?>" required>
            <label for="instance">Instance:</label>
            <select class="form-control" id="instance" name="instance" required>
                <option>Please select an instance</option>
                <?php foreach ($instanceRecord as $instanceName => $instanceUrl) { ?>
                <?php if (isset($activeInstance) && $activeInstance == $instanceName) { ?>
                <option selected value=<?php echo $instanceName; ?>><?php echo $instanceName; ?> </option>
                <?php } else {?>
                <option value=<?php echo $instanceName; ?>><?php echo $instanceName; ?> </option>;
                <?php } ?>
                <?php } ?>
                </select>
            <button type="submit">Get Sites</button>
        <?php
        return ob_get_clean();
    }
    public function sitesTable($sites): string{
        ob_start();
        ?>
        <table id="cbsconfiguration" class="table table-striped table-bordered" style="width:100%">
            <thead>
                <tr>
                    <th style="text-align: center;"> Site </th>
                      <th style="text-align: center;">Menu Type</th>
                      <th style="text-align: center;">Scan QR</th>
                      <th style="text-align: center;">Payment Option</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $siteDetail) { ?>
                <tr>
                    <td>
                        <input type="hidden" name="site_name<?= $siteDetail->siteid; ?>" value="<?= $siteDetail->siteid; ?>"><?= $siteDetail->site_name; ?>
                    </td>
                    <td>
                        <select class="form-control" name="menu_type_<?= $siteDetail->siteid ?>" id="purpose<?= $siteDetail->siteid; ?>" required  data-menu-type='<?= !empty($siteDetail->menu_type) ? $siteDetail->menu_type : '' ?>' rowId="<?= $siteDetail->siteid; ?>" data-area-id='<?= !empty($siteDetail->areaid) ? $siteDetail->areaid : '' ?>'>
                            <option value="Disabled" <?php echo $siteDetail->menu_type == 'Disabled' ? 'selected': "";?>>Disabled</option>
                            <option value="Default" <?php echo $siteDetail->menu_type == 'Default' ? 'selected': "";?>> Default</option>
                      </select>
                    </td>
                    <td>
                      <select class="form-control" name="pay_later_<?= $siteDetail->siteid; ?>" id="pay_later<?= $siteDetail->siteid; ?>">
                        <option value="Disabled" <?php echo $siteDetail->pay_later_control == 'Disabled' ? 'selected' : "" ; ?>> Disabled</option>
                        <option value="Enabled" <?php echo $siteDetail->pay_later_control == 'Enabled' ? 'selected' : "" ; ?>>Enabled</option>
                      </select>
                    </td>
                    <td>
                      <select class="form-control" name="payment_control_<?= $siteDetail->siteid; ?>" id="payment_control<?= $siteDetail->siteid; ?>">
                        <option value="stripe" <?php echo $siteDetail->payment_control == 'stripe' ? 'selected' : "" ; ?>>Stripe</option>
                        <option value="beyond" <?php echo $siteDetail->payment_control == 'beyond' ? 'selected' : "" ; ?>>Beyond</option>
                        <option value="clover" <?php echo $siteDetail->payment_control == 'clover' ? 'selected' : "" ; ?>>Clover</option>
                        <option value="authorize" <?php echo $siteDetail->payment_control == 'authorize' ? 'selected' : "" ; ?>>Authorize</option>
                        <option value="None" <?php echo $siteDetail->payment_control == 'None' ? 'selected' : "" ; ?>>None</option>
                      </select>
                    </td>
                  </tr>
                  <?php } ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}
