<?php

require_once 'vendor/autoload.php';

if(file_exists('.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
    $dotenv->load();
}

if (isset($_POST['formato']) && $_POST['formato'] == 'csv') {
    require 'report.php';
    return;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>Relatório</title>
  <link type="css" src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" />

  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap-theme.min.css" crossorigin="anonymous">
</head>
<body>
<div class="container-fluid p-3">
  <form class="form-inline" method="POST">
  <div class="form-row align-items-center">
    <div class="col-auto align-self-end">
      <label for="icmp">ID Evento</label>
      <input type="number" name="id" value="<?php echo $_POST['id']; ?>" class="form-control" placeholder="ID" />
    </div>
    <div class="col-auto align-self-end">
      <label for="icmp">Nome Evento</label>
      <input type="text" name="name" value="<?php echo $_POST['name']; ?>" class="form-control" placeholder="Nome evento" maxlength="150" />
    </div>
    <div class="col-auto align-self-end">
      <label for="icmp">Nome do host</label>
      <input type="text" name="host" value="<?php echo $_POST['host']; ?>" class="form-control" placeholder="Host" maxlength="30" />
    </div>
    <div class="col-auto align-self-end">
      <label for="icmp">Nome da ONU</label>
      <input type="text" name="onu" value="<?php echo $_POST['onu']; ?>" class="form-control" placeholder="Nome da ONU" maxlength="30" />
    </div>
    <div class="col-auto align-self-end">
      <label for="data-inicio">Data início</label>
      <input type="date" name="start-date" value="<?php echo $_POST['start-date']; ?>" class="form-control" id="data-inicio">
      <input type="time" name="start-time" value="<?php echo $_POST['start-time']; ?>" class="form-control" />
    </div>
    <div class="col-auto align-self-end">
      <label for="data-fim">Data fim</label>
      <input type="date" name="recovery-date" value="<?php echo $_POST['recovery-date']; ?>" class="form-control" id="data-fim">
      <input type="time" name="recovery-time" value="<?php echo $_POST['recovery-time']; ?>" class="form-control" />
    </div>
    <div class="col-auto align-self-end text-sm-center">
      <label for="icmp">ICMP</label>
      <input type="checkbox" name="icmp" class="form-control" id="icmp"<?php echo !empty($_POST['icmp'])? ' checked="checked"':'';?> />
    </div>
    <div class="col-auto align-self-end text-sm-center">
      <label for="separador">Separador CSV</label>
      <div class="form-check form-check-inline m-1">
        <input class="form-check-input" type="radio" name="separador" id="separador-ponto-e-virgula" value=";"<?php
            if (!isset($_POST['separador']) || (isset($_POST['separador']) && $_POST['separador'] == ';')) {
                echo ' checked="checked"';
            }?> />
        <label class="form-check-label" for="separador-ponto-e-virgula">;</label>
      </div>
      <div class="form-check form-check-inline m-1">
        <input class="form-check-input" type="radio" name="separador" id="separador-virgula" value=","<?php
            if (isset($_POST['separador']) && $_POST['separador'] == ',') {
                echo ' checked="checked"';
            }?> />
        <label class="form-check-label" for="separador-virgula">,</label>
      </div>
    </div>
    <div class="col-auto align-self-end">
      <button type="submit" class="btn btn-primary" name="formato" value="html">Gerar relatório</button>
      <button type="submit" class="btn btn-primary" name="formato" value="csv">Baixar CSV</button>
    </div>
  </form>
</div>
<?php
if (isset($_POST['formato']) && $_POST['formato'] == 'html') {
  ?><div class="container-fluid p-3"><?php
  require 'report.php';
  ?></div><?php
}
?>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.bundle.min.js"></script>
</body>
</html>