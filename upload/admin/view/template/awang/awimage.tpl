<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="breadcrumb">
    <?php foreach ($breadcrumbs as $breadcrumb) { ?>
    <?php echo $breadcrumb['separator']; ?><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a>
    <?php } ?>
  </div>
  <?php if ($error_warning) { ?>
  <div class="warning"><?php echo $error_warning; ?></div>
  <?php } ?>
  <?php if ($success) { ?>
  <div class="success"><?php echo $success; ?></div>
  <?php } ?>
  <div class="box">
    <div class="heading">
      <h1><img src="view/image/backup.png" alt="" /> <?php echo $heading_title; ?></h1>
    </div>
    <div class="content">
      <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form">
        <table class="form">
          <tr>
            <td colspan="4"><?php echo $entry_description; ?></td>
          </tr>
          <tr>
            <td width="25%"><?php echo $entry_miaimage; ?></td>
            <td><?php echo "                                                        " ?></td>
			<td><a href="<?php echo $chkimage; ?>" class="button"><span><?php echo $button_miaimage; ?></span></a></td>
          </tr>
          <tr>
            <td width="25%"><?php echo $entry_restore; ?></td>
            <td><?php echo "PLEASE BE SURE TO COPY TO THE DATA/PRODUCTS DIRECTORY" ?></td>
			<td><a href="<?php echo $upimage; ?>" class="button"><span><?php echo $button_import; ?></span></a></td>
          </tr>
        </table>
      </form>
	        <textarea wrap="off" style="width: 98%; height: 300px; padding: 5px; border: 1px solid #CCCCCC; background: #FFFFFF; overflow: scroll;"><?php echo $log; ?></textarea>
    </div>
  </div>
</div>
<script type="text/javascript"><!--

//--></script>
<?php echo $footer; ?>