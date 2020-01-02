# Relatórios Zabbix OLT & ONU & ICMP

## Instalação

Baixe o arquivo zip da versão mais recente do projeto **[aqui](https://github.com/lyseontech/zabbix-report/releases/latest)**.

Descompacte o projeto onde irá rodar a aplicação

### Utilizando Docker

Copie o arquivo `.env.example` para `.env`.

> **OBS**: Caso vá utilizar com um banco de dados externo, remova o serviço `db` do arquivo `docker-compose.yml` e configure o arquivo `.env` com as credenciais de acesso do banco.

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