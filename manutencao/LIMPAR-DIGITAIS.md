# Apagar as digitais físicas do leitor

O SQL executado no phpMyAdmin altera somente o MySQL. Para remover as digitais que estão na memória do leitor, use este utilitário separado de manutenção. Ele conversa por USB com o firmware C.E.S.A.R. já instalado no ESP32, sem regravar a placa, sem mudar a velocidade UART do sensor e sem acessar o banco.

Você precisa de Python 3 no computador conectado ao ESP32. No Windows:

```powershell
py -m pip install pyserial==3.5
```

Feche a conexão do leitor no site, o Monitor Serial da Arduino IDE e qualquer outro programa usando a porta COM. Confirme a porta do ESP32 no Gerenciador de Dispositivos. No terminal, execute substituindo COM5 pela porta correta e ajustando o caminho se necessário:

```powershell
py C:\xampp\htdocs\cesar-main\manutencao\limpar_digitais.py --porta COM5
```

O utilitário aguarda a inicialização da placa, consulta o inventário físico e mostra o identificador do ESP32 e os IDs ocupados. Para autorizar a exclusão, digite exatamente:

```text
APAGAR DIGITAIS
```

Qualquer outra resposta cancela antes de enviar exclusões. Após confirmar, ele consulta o inventário novamente. Se os IDs mudarem nesse intervalo, a operação é interrompida sem apagar nada e precisa ser reiniciada.

Cada ID é removido com o comando `delete` e `confirm:true` do firmware. O script só considera o leitor vazio depois que uma nova consulta `status` retornar um inventário válido sem IDs ocupados. Falhas de comunicação, inventários ausentes ou malformados, mudança do identificador do ESP32, resultados de outra requisição e respostas vencidas não são interpretados como memória vazia.

Se ocorrer uma falha durante a remoção, o utilitário interrompe as exclusões, tenta consultar novamente e informa os IDs remanescentes. Uma exclusão com tempo esgotado pode ter sido executada no leitor; o script não repete automaticamente esse comando nem declara sucesso sem confirmação. Não desconecte o cabo durante a limpeza. Se interromper o programa, a memória pode ficar parcialmente apagada; consulte novamente antes de continuar.

O slot 0 é reservado e não pode ser excluído pelo firmware atual. Se alguma ferramenta anterior tiver gravado uma digital nesse ID, ele aparecerá como remanescente e o utilitário retornará falha, sem alegar limpeza completa. O cadastro normal do C.E.S.A.R. utiliza IDs a partir de 1.

A comunicação USB permanece em 115200 bps, independentemente de o seu leitor estar configurado para UART de 9600 ou 57600 bps no firmware. Este utilitário não altera essa configuração. Se o leitor responder `inventory_unavailable` ou `sensor_offline`, resolva a comunicação antes de tentar apagar as digitais. Se responder `busy`, encerre a operação pendente no site e desconecte o site antes de executar a manutenção.

Códigos de saída: 0 indica memória vazia confirmada; 1 indica falha, memória remanescente ou limpeza interrompida; 2 indica erro de conexão, dependência ou protocolo; 3 indica cancelamento pela confirmação. Sem `--porta`, nada é aberto ou apagado.

Os vínculos `ID_DIGITAL` do MySQL não são alterados pelo utilitário. O reset do banco é uma etapa separada. Depois de apagar ambos, as digitais precisam ser cadastradas novamente no leitor e vinculadas aos atiradores no sistema.

Para zerar tudo, confirme primeiro que este programa terminou com código 0 e informou memória vazia. Só depois execute `sql/resetarBD.sql` no banco selecionado no phpMyAdmin. O SQL recria os administradores `admin.1` e `admin.2`, ambos com senha `tg02079`. Se a limpeza física falhar, confira e resolva o resultado antes de apagar os vínculos do banco.
