# Relatórios Zabbix SLA

Geração de relatório de SLA para o Zabbix

## Instalação

Baixe o arquivo zip da versão mais recente do projeto **[aqui](https://github.com/lyseontech/zabbix-report/releases/latest)**.

Descompacte o projeto onde irá rodar a aplicação

### Utilizando Docker

Copie o arquivo `.env.example` para `.env`.

> **OBS¹**: Caso vá utilizar com um banco de dados externo, remova o serviço `db` do arquivo `docker-compose.yml` e configure o arquivo `.env` com as credenciais de acesso do banco.

> **OBS²**: Caso utilize um banco de dados na rede do Docker, o volume é persistido na pasta `.docker/volumes/data` e para levantar a aplicação com um dump de banco novo, certifique-se de que a pasta `.docker/volumes/data` não exista e coloque o dump novo na pasta `.docker/volumes/dump`.

Execute o comando que segue na raiz do projeto:

```bash
docker-compose up
```

### Utilizando servidor built-in do PHP

Copie o arquivo `.env.example` para `.env`.

> **OBS**: lembre-se de definir as credenciais de banco corretamente.

Execute o comando que segue na raiz do projeto:

```bash
php -S localhost:8000
```

## Geração de release

Clone o projeto, execute `composer install` e rode o comando que segue para
criar o pacote da nova release:

```bash
zip -r zabbix-report.zip composer.* .env index.php LICENSE README.md src/ vendor/
```
