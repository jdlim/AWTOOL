<?php 
class ControllerAwangRlmexport extends Controller { 
	private $error = array();
	
	public function index() {
		$this->load->language('awang/rlmexport');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('awang/rlmexport');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && ($this->validate())) {
			if ((isset( $this->request->files['upload'] )) && (is_uploaded_file($this->request->files['upload']['tmp_name']))) {
				$file = $this->request->files['upload']['tmp_name'];
				if ($this->model_awang_rlmexport->upload($file)===TRUE) {
					$this->session->data['success'] = $this->language->get('text_success');
					$this->response->redirect($this->url->link('awang/rlmexport', 'token=' . $this->session->data['token'], 'SSL'));
				}
				else {
					$this->error['warning'] = $this->language->get('error_upload');
				}
			}
			if ((isset( $this->request->files['employee'] )) && (is_uploaded_file($this->request->files['employee']['tmp_name']))) {
				$file = $this->request->files['employee']['tmp_name'];
				if ($this->model_awang_rlmexport->employee($file)===TRUE) {
					$this->session->data['success'] = $this->language->get('text_success');
					$this->response->redirect($this->url->link('awang/rlmexport', 'token=' . $this->session->data['token'], 'SSL'));
				}
				else {
					$this->error['warning'] = $this->language->get('error_upload');
				}
			}
			if ((isset( $this->request->files['invoicerec'] )) && (is_uploaded_file($this->request->files['invoicerec']['tmp_name']))) {
				$file = $this->request->files['invoicerec']['tmp_name'];
				if ($this->model_awang_rlmexport->invoicerec($file)===TRUE) {
					$this->session->data['success'] = $this->language->get('text_success');
					$this->response->redirect($this->url->link('awang/rlmexport', 'token=' . $this->session->data['token'], 'SSL'));
				}
				else {
					$this->error['warning'] = $this->language->get('error_upload');
				}
			}
		}

		if (!empty($this->session->data['export_error']['errstr'])) {
			$this->error['warning'] = $this->session->data['export_error']['errstr'];
			if (!empty($this->session->data['export_nochange'])) {
				$this->error['warning'] .= '<br />'.$this->language->get( 'text_nochange' );
			}
			$this->error['warning'] .= '<br />'.$this->language->get( 'text_log_details' );
		}
		unset($this->session->data['export_error']);
		unset($this->session->data['export_nochange']);

		$data['heading_title'] = $this->language->get('heading_title');
		$data['entry_restore'] = $this->language->get('entry_restore');
		$data['entry_description'] = $this->language->get('entry_description');
		$data['button_import'] = $this->language->get('button_import');
		$data['button_export'] = $this->language->get('button_export');
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
			if(isset($this->session->data['uploadstatus'])) {
				$data['success'] = $this->session->data['uploadstatus'];
				unset($this->session->data['uploadstatus']);
				unset($this->session->data['success']);
			} else {
				$data['success'] = $this->session->data['success'];
				unset($this->session->data['success']);
			}
		} else {
			$data['success'] = '';
		}
		
		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text'      => $this->language->get('text_home'),
			'href'      => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], 'SSL'),
			'separator' => FALSE
		);

		$data['breadcrumbs'][] = array(
			'text'      => $this->language->get('heading_title'),
			'href'      => $this->url->link('awang/rlmexport', 'token=' . $this->session->data['token'], 'SSL'),
			'separator' => ' :: '
		);
		
		$data['action'] = $this->url->link('awang/rlmexport', 'token=' . $this->session->data['token'], 'SSL');
		$data['export'] = $this->url->link('awang/rlmexport/download', 'token=' . $this->session->data['token'], 'SSL');
		$data['post_max_size'] = $this->return_bytes( ini_get('post_max_size') );
		$data['upload_max_filesize'] = $this->return_bytes( ini_get('upload_max_filesize') );

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		
		$this->response->setOutput($this->load->view('awang/rlmexport.tpl', $data));
	}


	function return_bytes($val)
	{
		$val = trim($val);
	
		switch (strtolower(substr($val, -1)))
		{
			case 'm': $val = (int)substr($val, 0, -1) * 1048576; break;
			case 'k': $val = (int)substr($val, 0, -1) * 1024; break;
			case 'g': $val = (int)substr($val, 0, -1) * 1073741824; break;
			case 'b':
				switch (strtolower(substr($val, -2, 1)))
				{
					case 'm': $val = (int)substr($val, 0, -2) * 1048576; break;
					case 'k': $val = (int)substr($val, 0, -2) * 1024; break;
					case 'g': $val = (int)substr($val, 0, -2) * 1073741824; break;
					default : break;
				} break;
			default: break;
		}
		return $val;
	}


	public function download() {
		if ($this->validate()) {

			// send the categories, products and options as a spreadsheet file
			$this->load->model('awang/rlmexport');
			$this->model_awang_rlmexport->download();
			$this->redirect( $this->url->link( 'awang/rlmexport', 'token='.$this->request->get['token'], 'SSL' ) );

		} else {

			// return a permission error page
			return $this->forward('error/permission');
		}
	}


	private function validate() {
		if (!$this->user->hasPermission('modify', 'awang/rlmexport')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		
		if (!$this->error) {
			return TRUE;
		} else {
			return FALSE;
		}
	}
}
?>