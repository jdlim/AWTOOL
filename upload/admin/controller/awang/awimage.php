<?php 
class ControllerAwangAwimage extends Controller { 
	private $error = array();
	
	public function index() {		
		$this->language->load('awang/awimage');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('awang/awimage');
		
		$data['heading_title'] = $this->language->get('heading_title');
		$data['heading_title'] = $this->language->get('heading_title');
		$data['entry_restore'] = $this->language->get('entry_restore');
		$data['entry_miaimage'] = $this->language->get('entry_miaimage');
		$data['entry_description'] = $this->language->get('entry_description');
		$data['button_import'] = $this->language->get('button_import');
		$data['button_miaimage'] = $this->language->get('button_miaimage');
		$data['tab_general'] = $this->language->get('tab_general');
		$data['error_select_file'] = $this->language->get('error_select_file');
		$data['error_post_max_size'] = str_replace( '%1', ini_get('post_max_size'), $this->language->get('error_post_max_size') );
		$data['error_upload_max_filesize'] = str_replace( '%1', ini_get('upload_max_filesize'), $this->language->get('error_upload_max_filesize') );
 		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}
		 
		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
		
			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}
		
  		$data['breadcrumbs'] = array();

   		$data['breadcrumbs'][] = array(
       		'text'      => $this->language->get('text_home'),
			'href'      => $this->url->link('common/home', 'token=' . $this->session->data['token'], 'SSL'),       		
      		'separator' => false
   		);

   		$data['breadcrumbs'][] = array(
       		'text'      => $this->language->get('heading_title'),
			'href'      => $this->url->link('awang/awimage', 'token=' . $this->session->data['token'], 'SSL'),
      		'separator' => ' :: '
   		);
		
		$data['upimage'] = $this->url->link('awang/awimage/upimage', 'token=' . $this->session->data['token'], 'SSL');
		$data['chkimage'] = $this->url->link('awang/awimage/chkimage', 'token=' . $this->session->data['token'], 'SSL');

		if(isset($this->session->data['log'])) {
			$data['log'] = $this->session->data['log'];
			unset($this->session->data['log']);
		} else {
			$data['log'] = '';
		}

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && ($this->validate())) {
		
//			$file = DIR_LOGS . $this->config->get('config_error_filename');
//			if (file_exists($file)) {
//				$this->data['log'] = file_get_contents($file, FILE_USE_INCLUDE_PATH, null);
		}
/*		$this->template = 'awang/awimage.tpl';
		$this->children = array(
			'common/header',
			'common/footer'
		);
*/
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');	

		//		$this->response->setOutput($this->render());
		$this->response->setOutput($this->load->view('awang/awimage.tpl', $data));
	}

	public function chkimage() {
		$this->load->model('awang/awimage');	
	$this->session->data['log'] = "Styles with no images assigned...\n";
	$this->model_awang_awimage->checkme();
	$this->reponse->redirect($this->url->link('awang/awimage', 'token=' . $this->session->data['token'], 'SSL'));
					// TODO:  This is the sql code to find the missing rows: SELECT oc_product.product_id, oc_product.model FROM oc_product LEFT JOIN oc_product_image ON oc_product.product_id = oc_product_image.product_id WHERE oc_product_image.product_id IS NULL;

	}
	
	public function upimage() {

		$this->load->model('awang/awimage');
		$this->model_awang_awimage->storeProductImage();
/*		$file = DIR_LOGS . $this->config->get('config_error_filename');
		
		$handle = fopen($file, 'w+'); 
				
		fclose($handle); 			
*/


//$objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
// foreach($objects as $name => $object){
//     echo "$name <br>\n";
// }
 
// die();
		$this->session->data['success'] = $this->session->data['img_success'];
		
		$this->response->redirect($this->url->link('awang/awimage', 'token=' . $this->session->data['token'], 'SSL'));		
	}
}
?>