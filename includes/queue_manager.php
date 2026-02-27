<?php
/**
 * Queue Manager - Gestionare coadă procesare imagini
 *
 * Folosit pentru a adăuga imagini în coada de procesare nocturnă
 *
 * @version 1.0.0
 * @date 28 Februarie 2026
 */

class QueueManager {

    private $conn;

    /**
     * Constructor
     * @param mysqli $conn - Conexiune la baza de date centrală
     */
    public function __construct($conn = null) {
        if ($conn) {
            $this->conn = $conn;
        } else if (function_exists('getCentralDbConnection')) {
            $this->conn = getCentralDbConnection();
        }
    }

    /**
     * Adaugă o imagine în coada de procesare
     *
     * @param int $user_id - ID utilizator
     * @param int $colectie_id - ID colecție
     * @param string $image_path - Calea relativă către imagine
     * @param array $options - Opțiuni adiționale (locatie, cutie, context, prioritate)
     * @return array - Rezultat operație
     */
    public function addToQueue($user_id, $colectie_id, $image_path, $options = []) {
        if (!$this->conn) {
            return ['success' => false, 'error' => 'Nu există conexiune la DB'];
        }

        // Verifică dacă imaginea există deja în queue
        $check_sql = "SELECT id, status FROM procesare_imagini_queue
                      WHERE cale_imagine = ? AND status IN ('pending', 'processing')";
        $check_stmt = mysqli_prepare($this->conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "s", $image_path);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $existing = mysqli_fetch_assoc($check_result);
            return [
                'success' => true,
                'message' => 'Imaginea este deja în coadă',
                'queue_id' => $existing['id'],
                'status' => $existing['status']
            ];
        }

        // Extrage opțiunile
        $locatie = $options['locatie'] ?? null;
        $cutie = $options['cutie'] ?? null;
        $context = $options['context'] ?? null;
        $prioritate = $options['prioritate'] ?? 5;
        $id_obiect = $options['id_obiect'] ?? null;
        $nume_original = $options['nume_original'] ?? basename($image_path);

        // Inserează în queue
        $sql = "INSERT INTO procesare_imagini_queue
                (id_utilizator, id_colectie, id_obiect, cale_imagine, nume_original,
                 locatie, cutie, context_manual, prioritate, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";

        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiisssssi",
            $user_id, $colectie_id, $id_obiect, $image_path, $nume_original,
            $locatie, $cutie, $context, $prioritate);

        if (mysqli_stmt_execute($stmt)) {
            $queue_id = mysqli_insert_id($this->conn);
            return [
                'success' => true,
                'message' => 'Imaginea a fost adăugată în coadă pentru procesare',
                'queue_id' => $queue_id
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Eroare la inserare: ' . mysqli_error($this->conn)
            ];
        }
    }

    /**
     * Adaugă multiple imagini în queue
     *
     * @param int $user_id
     * @param int $colectie_id
     * @param array $images - Array de căi către imagini
     * @param array $options - Opțiuni comune
     * @return array
     */
    public function addMultipleToQueue($user_id, $colectie_id, $images, $options = []) {
        $results = [];
        $success_count = 0;

        foreach ($images as $image_path) {
            $result = $this->addToQueue($user_id, $colectie_id, $image_path, $options);
            $results[] = [
                'image' => $image_path,
                'result' => $result
            ];
            if ($result['success']) {
                $success_count++;
            }
        }

        return [
            'success' => true,
            'total' => count($images),
            'added' => $success_count,
            'details' => $results
        ];
    }

    /**
     * Obține statusul unei imagini din queue
     *
     * @param int $queue_id
     * @return array|null
     */
    public function getStatus($queue_id) {
        $sql = "SELECT * FROM procesare_imagini_queue WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $queue_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        return mysqli_fetch_assoc($result);
    }

    /**
     * Obține toate imaginile pending pentru un utilizator
     *
     * @param int $user_id
     * @return array
     */
    public function getPendingForUser($user_id) {
        $sql = "SELECT * FROM procesare_imagini_queue
                WHERE id_utilizator = ? AND status IN ('pending', 'processing')
                ORDER BY data_adaugare DESC";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $items = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Obține rezultatele procesate pentru un utilizator
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public function getCompletedForUser($user_id, $limit = 50) {
        $sql = "SELECT * FROM procesare_imagini_queue
                WHERE id_utilizator = ? AND status = 'completed'
                ORDER BY data_completare DESC
                LIMIT ?";
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $user_id, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $items = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // Parse JSON rezultat
            if ($row['rezultat_json']) {
                $row['rezultat'] = json_decode($row['rezultat_json'], true);
            }
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Anulează o cerere din queue
     *
     * @param int $queue_id
     * @param int $user_id - Pentru verificare acces
     * @return array
     */
    public function cancelRequest($queue_id, $user_id) {
        // Verifică că aparține utilizatorului și e pending
        $check_sql = "SELECT id FROM procesare_imagini_queue
                      WHERE id = ? AND id_utilizator = ? AND status = 'pending'";
        $check_stmt = mysqli_prepare($this->conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ii", $queue_id, $user_id);
        mysqli_stmt_execute($check_stmt);

        if (mysqli_stmt_get_result($check_stmt)->num_rows === 0) {
            return ['success' => false, 'error' => 'Cererea nu există sau nu poate fi anulată'];
        }

        // Șterge
        $delete_sql = "DELETE FROM procesare_imagini_queue WHERE id = ?";
        $delete_stmt = mysqli_prepare($this->conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "i", $queue_id);

        if (mysqli_stmt_execute($delete_stmt)) {
            return ['success' => true, 'message' => 'Cererea a fost anulată'];
        }

        return ['success' => false, 'error' => 'Eroare la anulare'];
    }

    /**
     * Statistici queue pentru un utilizator
     *
     * @param int $user_id
     * @return array
     */
    public function getStatsForUser($user_id) {
        $sql = "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(numar_obiecte_gasite) as total_objects,
                    SUM(tokens_utilizate) as total_tokens,
                    SUM(cost_estimat) as total_cost
                FROM procesare_imagini_queue
                WHERE id_utilizator = ?";

        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        return mysqli_fetch_assoc($result);
    }

    /**
     * Statistici globale (pentru admin)
     *
     * @return array
     */
    public function getGlobalStats() {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(numar_obiecte_gasite) as total_objects,
                    SUM(tokens_utilizate) as total_tokens,
                    SUM(cost_estimat) as total_cost
                FROM procesare_imagini_queue";

        $result = mysqli_query($this->conn, $sql);
        return mysqli_fetch_assoc($result);
    }
}

/**
 * Funcție helper pentru adăugare rapidă în queue
 */
function addImageToProcessingQueue($user_id, $colectie_id, $image_path, $options = []) {
    $manager = new QueueManager();
    return $manager->addToQueue($user_id, $colectie_id, $image_path, $options);
}
