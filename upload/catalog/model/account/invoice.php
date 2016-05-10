<?php
class ModelAccountInvoice extends Model {
	public function getInvoices($data = array()) {
		$sql = "SELECT * FROM `" . DB_PREFIX . "customer_invoice` WHERE customer_id = '" . (int)$this->customer->getId() . "'";

		$sort_data = array(
			'ar_amount',
			'subdivname',
			'units',
			'invoice_date'
		);

		if (isset($data['sort']) && in_array($data['sort'], $sort_data)) {
			$sql .= " ORDER BY " . $data['sort'];
		} else {
			$sql .= " ORDER BY date_added";
		}

		if (isset($data['order']) && ($data['order'] == 'DESC')) {
			$sql .= " DESC";
		} else {
			$sql .= " ASC";
		}

		if (isset($data['start']) || isset($data['limit'])) {
			if ($data['start'] < 0) {
				$data['start'] = 0;
			}

			if ($data['limit'] < 1) {
				$data['limit'] = 20;
			}

			$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
		}

		$query = $this->db->query($sql);

		return $query->rows;
	}

	public function getTotalInvoices() {

		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "customer_invoice` WHERE customer_id = '" . (int)$this->customer->getId() . "'");

		return $query->row['total'];
	}

	public function getTotalAmount() {
		$query = $this->db->query("SELECT SUM(ar_amount) AS total FROM `" . DB_PREFIX . "customer_invoice` WHERE customer_id = '" . (int)$this->customer->getId() . "' GROUP BY customer_id");

		if ($query->num_rows) {
			return $query->row['total'];
		} else {
			return 0;
		}
	}
}