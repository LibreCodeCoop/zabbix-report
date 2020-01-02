<?php

$dbh = new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWD'));
$sql = <<<QUERY
    SELECT start.eventid AS id,
        start.name,
        CASE WHEN hosts.host IS NOT NULL THEN hosts.host
                WHEN alert_start.message LIKE '%Host:%' THEN REPLACE(REPLACE(REGEXP_SUBSTR(alert_start.message, 'Host:.*\n'), 'Host: ', ''), '\n', '')
                WHEN alert_start.message LIKE '%<b>%' THEN REPLACE(REPLACE(REGEXP_SUBSTR(alert_start.message, '<b>.*</b>'), '<b> ', ''), ' </b>', '')
            END AS host,
        TRIM(REGEXP_SUBSTR(start.name, 'onu_[0-9/: ]+')) AS onu,
        FROM_UNIXTIME(start.clock) AS start_time,
        FROM_UNIXTIME(recovery.clock) AS recovery_time,
        recovery.clock - start.clock AS duration,
        CASE WHEN start.name LIKE '%ICMP%' THEN 1 ELSE 0 END as icmp
    FROM events start
    LEFT JOIN event_recovery er ON er.eventid = start.eventid
    LEFT JOIN events recovery ON recovery.eventid = er.r_eventid
    LEFT JOIN alerts alert_start ON alert_start.eventid = start.eventid AND alert_start.mediatypeid = 5
    LEFT JOIN triggers ON start.objectid = triggers.triggerid
    LEFT JOIN functions ON functions.triggerid = triggers.triggerid
    LEFT JOIN items ON items.itemid = functions.itemid
    LEFT JOIN hosts ON items.hostid = hosts.hostid
     WHERE (start.severity = 5 OR recovery.severity = 0)
       AND lower(start.name) regexp 'olt|onu|icmp'
    QUERY;
$params = [];
if (!empty($_POST['id']) && is_numeric($_POST['id'])) {
    $sql.= "\n  AND start.eventid = ?";
    $params[] = (int)$_POST['id'];
}
if (!empty($_POST['name'])) {
    $value = substr(trim(strtolower($_POST['name'])), 0, 150);
    $sql.= "\n  AND LOWER(start.name) LIKE ?";
    $params[] = '%' . $value . '%';
}
if (!empty($_POST['host'])) {
    $value = substr(trim(strtolower($_POST['host'])), 0, 30);
    $sql.= "\n  AND (LOWER(hosts.host) LIKE ? OR LOWER(alert_start.message) LIKE ?)";
    $params[] = '%' . $value . '%';
    $params[] = '%' . $value . '%';
}
if (!empty($_POST['onu'])) {
    $value = substr(trim(strtolower($_POST['onu'])), 0, 30);
    $sql.= "\n  AND LOWER(start.name) LIKE ?";
    $params[] = '%' . $value . '%';
}
if (!empty($_POST['start-date'])) {
    $value = DateTime::createFromFormat('Y-m-d', $_POST['start-date']);
    if ($value) {
        if (!empty($_POST['start-time'])) {
            $startTime = DateTime::createFromFormat('Y-m-d H:i', $_POST['start-date'] . ' ' . $_POST['start-time']);
        } else {
            $startTime = $value;
        }
    }
    if ($startTime && !empty($_POST['recovery-date'])) {
        $value = DateTime::createFromFormat('Y-m-d', $_POST['recovery-date']);
        if ($value) {
            if (!empty($_POST['recovery-time'])) {
                $recoveryTime = DateTime::createFromFormat('Y-m-d H:i:s', $_POST['recovery-date'] . ' ' . $_POST['recovery-time'].':59');
            } else {
                $recoveryTime = DateTime::createFromFormat('Y-m-d H:i:s', $_POST['recovery-date'] . ' 23:59:59');
            }
        }
    }
    if ($startTime && $recoveryTime) {
        $sql.= "\n  AND start.clock >= ?";
        $sql.= "\n  AND recovery.clock <= ?";
        $params[] = $startTime->format('U');
        $params[] = $recoveryTime->format('U');
    }
}
if (!empty($_POST['icmp'])) {
    $sql.= "\n  AND start.name LIKE '%ICMP%'";
}

$sth = $dbh->prepare($sql);


$sth->execute($params);

if (isset($_POST['formato']) && $_POST['formato'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename='.date('Ymd_His').'.csv');

    $out = fopen('php://output', 'w');

    $delimiter = $_POST['separador'] == ';' ? ';' : ',';
    $row = $sth->fetch(PDO::FETCH_ASSOC);
    fputcsv($out, array_keys($row), $delimiter);
    fputcsv($out, $row, $delimiter);
    while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, $row, $delimiter);
    }
} else {
    $row = $sth->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo 'Sem resultados';
    } else {
        ?>
        <table class="table">
        <thead class="thead-dark">
            <tr><?php
            foreach (array_keys($row) as $key) {
                ?>
                <th scope="col"><?php echo $key; ?></th>
                <?php
            }
            ?>
            </tr>
        </thead>
        <tbody>
            <?php
            while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
                echo '<tr><td>'.implode('</td><td>', $row).'</td></tr>';
            }
            ?>
        </tbody>
        <?php
    }
}