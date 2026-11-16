# C.E.S.A.R. — Controle de Armaria (Tiro de Guerra)

Sistema web desenvolvido em PHP e MySQL para o controle seguro e organizado de armamentos, atiradores da tropa, cautelas (empréstimos e devoluções) e auditoria de ações.

---

## 📁 Estrutura do Projeto

```text
cesar/
├── assets/                  # Arquivos estáticos da interface
│   ├── css/                 # Folhas de estilo (estilo.css, painel.css)
│   ├── img/                 # Logotipos e imagens da armaria
│   └── js/                  # Scripts JavaScript (painel.js, busca.js, pessoas.js, perfil.js)
├── config/                  # Configurações do sistema
│   └── database.php         # Conexão PDO com MySQL/MariaDB
├── includes/                # Regras e funções centrais do backend
│   ├── funcoes.php          # Funções utilitárias, CSRF, auditoria e timezone (Brasília)
│   ├── layout.php           # Template visual do painel administrativo
│   ├── proteger.php         # Middleware de proteção de rota e timeout de inatividade
│   ├── sessao.php           # Configuração segura de cookies e sessões PHP
│   ├── upload-perfil.php    # Processamento de foto de perfil de usuários
│   └── upload-pessoa.php    # Processamento de foto dos atiradores
├── pages/                   # Páginas da aplicação
│   ├── catalogo.php         # Listagem do acervo de armamentos
│   ├── catalogo-form.php    # Cadastro e edição de armas
│   ├── catalogo-salvar.php  # Processamento de salvamento de armas
│   ├── emprestimos.php      # Listagem e consulta de cautelas
│   ├── emprestimo-form.php  # Formulário de retirada e devolução de cautelas
│   ├── emprestimo-salvar.php# Processamento de cautela e devolução com avaria
│   ├── inicio.php           # Dashboard com contadores e estatísticas
│   ├── login.php            # Tela de autenticação segura
│   ├── logs.php             # Auditoria geral de ações no sistema
│   ├── logs-emprestimos.php # Histórico detalhado de movimentações
│   ├── minha-conta.php      # Alteração de senha e foto do usuário logado
│   ├── operadores.php       # Gestão de armeiros (exclusivo Administrador)
│   ├── pessoas.php          # Listagem dos atiradores da tropa
│   ├── pessoa-form.php      # Ficha do atirador, biometria e foto
│   ├── pessoa-salvar.php    # Processamento de salvamento do atirador
│   └── sair.php             # Encerramento seguro de sessão
├── sql/                     # Scripts de banco de dados
│   ├── schema.sql           # DDL com as tabelas oficiais (ARMAMENTO, USUARIO, ATIRADOR, CAUTELA, LOG_SISTEMA)
│   ├── insert_usuario.sql   # Carga inicial de usuários com hashes Bcrypt
│   └── seed_dados_iniciais.sql # Dados demonstrativos (armas e atiradores)
└── storage/                 # Armazenamento protegido de fotos
    ├── fotos-perfis/        # Fotos de perfil dos armeiros e administradores
    └── fotos-pessoas/       # Fotos dos atiradores cadastrados
```

---

## 🚀 Instalação e Execução (XAMPP)

1. **Coloque a pasta no XAMPP:**
   Certifique-se de que o projeto esteja em `C:\xampp\htdocs\cesar`.

2. **Inicie os serviços:**
   Abra o **XAMPP Control Panel** e inicie o **Apache** e o **MySQL**.

3. **Crie e configure o banco de dados:**
   - Acesse o **phpMyAdmin** (`http://localhost/phpmyadmin`) ou cliente MySQL (Workbench/DBeaver).
   - Execute o script [`sql/schema.sql`](sql/schema.sql) para criar o banco `cesar` e suas tabelas.
   - Execute o script [`sql/insert_usuario.sql`](sql/insert_usuario.sql) para criar os usuários iniciais.
   - *(Opcional)* Execute [`sql/seed_dados_iniciais.sql`](sql/seed_dados_iniciais.sql) para carregar armas e atiradores de exemplo.

4. **Acesse no navegador:**
   - Abra [http://localhost/cesar](http://localhost/cesar).

---

## 🔑 Credenciais Padrão

| Usuário (Login) | Senha Inicial | Perfil |
| :--- | :--- | :--- |
| `carlos.henrique` | `123456` | Administrador |
| `fernando.souza` | `123456` | Administrador |
| `joao.pedro` | `123456` | Armeiro |
| `marcos.vinicius` | `123456` | Armeiro |

---

## 🔒 Segurança Implementada

- **Criptografia de Senhas:** Todas as senhas utilizam algoritmo nativo Bcrypt (`password_hash` / `password_verify`).
- **Proteção contra CSRF:** Formulários protegidos com tokens criptográficos descartáveis.
- **Ciclo de Vida de Sessão:** A sessão encerra automaticamente ao fechar o navegador ou aba, exigindo nova autenticação ao reabrir.
- **Confirmação ao Fechar:** Diálogo nativo de confirmação para prevenir fechamentos acidentais da aba.
- **Timeout de Inatividade:** Sessões inativas por mais de 30 minutos são automaticamente desconectadas.
- **Prepared Statements (PDO):** Todas as consultas utilizam parâmetros vinculados para prevenir SQL Injection.
