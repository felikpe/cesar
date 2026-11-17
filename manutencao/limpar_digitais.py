import argparse
import json
import re
import secrets
import sys
import time


class ErroProtocolo(RuntimeError):
    pass


class ClienteSerial:
    def __init__(self, conexao, timeout=12.0, relogio=time.monotonic):
        self.conexao = conexao
        self.timeout = timeout
        self.relogio = relogio
        self.buffer = bytearray()
        self.descartando = False
        self.identidade = None

    def receber_linha(self):
        if b"\n" not in self.buffer:
            quantidade = min(max(int(getattr(self.conexao, "in_waiting", 0)), 1), 1024)
            dados = self.conexao.read(quantidade)
            if not dados:
                return None
            self.buffer.extend(dados)
        if b"\n" not in self.buffer:
            if len(self.buffer) > 32768:
                self.buffer.clear()
                self.descartando = True
            return None
        linha, _, restante = self.buffer.partition(b"\n")
        self.buffer = bytearray(restante)
        if self.descartando or len(linha) > 32768:
            self.descartando = False
            return None
        return linha

    def requisitar(self, comando, **campos):
        identificador = secrets.token_hex(16)
        pedido = {"v": 1, "request": identificador, "cmd": comando, **campos}
        dados = (json.dumps(pedido, separators=(",", ":")) + "\n").encode("utf-8")
        enviados = self.conexao.write(dados)
        if enviados != len(dados):
            raise ErroProtocolo("Envio serial incompleto; o resultado da operação é incerto.")
        self.conexao.flush()
        fim = self.relogio() + self.timeout
        while self.relogio() < fim:
            linha = self.receber_linha()
            if linha is None:
                continue
            try:
                resposta = json.loads(linha.decode("utf-8"))
            except (UnicodeError, ValueError):
                continue
            if not isinstance(resposta, dict):
                continue
            if type(resposta.get("v")) is not int or resposta["v"] != 1:
                continue
            if resposta.get("request") != identificador or resposta.get("cmd") != comando:
                continue
            if resposta.get("event") == "progress":
                continue
            if resposta.get("event") != "result" or type(resposta.get("ok")) is not bool:
                raise ErroProtocolo("Resposta serial com formato inválido.")
            return resposta
        raise ErroProtocolo("Tempo esgotado aguardando " + comando + ". O resultado pode ser incerto.")

    def conferir_identidade(self, resposta):
        dispositivo = resposta.get("device")
        capacidade = resposta.get("capacity")
        if not isinstance(dispositivo, str) or not re.fullmatch(r"[0-9A-Fa-f]{12}", dispositivo):
            raise ErroProtocolo("Identificador do ESP32 ausente ou inválido.")
        if type(capacidade) is not int or not 2 <= capacidade <= 1024:
            raise ErroProtocolo("Capacidade do leitor ausente ou inválida.")
        identidade = (dispositivo.upper(), capacidade)
        if self.identidade is not None and self.identidade != identidade:
            raise ErroProtocolo("O ESP32 ou a capacidade do leitor mudou. A limpeza foi interrompida.")
        self.identidade = identidade
        return identidade

    def status(self):
        resposta = self.requisitar("status")
        if resposta["ok"] is not True:
            mensagem = resposta.get("message") or resposta.get("code") or "Falha sem descrição."
            raise ErroProtocolo("O leitor não confirmou o inventário: " + str(mensagem))
        if resposta.get("code") != "ready":
            raise ErroProtocolo("A consulta de inventário retornou um resultado inesperado.")
        if resposta.get("sensor") is not True or resposta.get("inventory") is not True:
            raise ErroProtocolo("Leitor desconectado ou inventário não confirmado. Nenhuma exclusão será enviada.")
        self.conferir_identidade(resposta)
        ocupados = resposta.get("occupied")
        if not isinstance(ocupados, list):
            raise ErroProtocolo("A resposta não contém uma lista de IDs ocupados.")
        capacidade = self.identidade[1]
        if any(type(slot) is not int or not 0 <= slot < capacidade for slot in ocupados):
            raise ErroProtocolo("O inventário contém IDs inválidos.")
        if len(ocupados) != len(set(ocupados)):
            raise ErroProtocolo("O inventário contém IDs repetidos.")
        return sorted(ocupados)

    def excluir(self, slot):
        if self.identidade is None or type(slot) is not int or not 1 <= slot < self.identidade[1]:
            raise ErroProtocolo("Exclusão recusada: ID fora do inventário permitido.")
        resposta = self.requisitar("delete", slot=slot, confirm=True)
        self.conferir_identidade(resposta)
        if (resposta["ok"] is not True or resposta.get("code") != "deleted"
                or type(resposta.get("slot")) is not int or resposta.get("slot") != slot):
            mensagem = resposta.get("message") or resposta.get("code") or "Resposta inesperada."
            raise ErroProtocolo("Exclusão do ID " + str(slot) + " não confirmada: " + str(mensagem))
        if resposta.get("sensor") is not True or resposta.get("inventory") is not True:
            raise ErroProtocolo("O leitor ou o inventário ficou indisponível durante a exclusão.")


def apresentar_slots(ocupados):
    return ", ".join(str(slot) for slot in ocupados) if ocupados else "nenhum"


def limpar(cliente, perguntar=input, informar=print):
    iniciais = cliente.status()
    dispositivo, capacidade = cliente.identidade
    informar("ESP32: " + dispositivo + " | capacidade: " + str(capacidade))
    informar("IDs ocupados: " + apresentar_slots(iniciais))
    if not iniciais:
        finais = cliente.status()
        if finais:
            informar("O inventário mudou; nenhuma exclusão foi enviada. IDs atuais: " + apresentar_slots(finais))
            return 1
        informar("Memória vazia confirmada por uma nova consulta ao leitor.")
        return 0
    if 0 in iniciais:
        informar("O ID 0 está ocupado e o firmware atual não permite excluí-lo. Ele será informado como remanescente.")
    informar("Esta operação remove as digitais físicas do leitor e não pode ser desfeita.")
    confirmacao = perguntar("Digite APAGAR DIGITAIS para continuar: ")
    if confirmacao.strip() != "APAGAR DIGITAIS":
        informar("Cancelado. Nenhuma exclusão foi enviada.")
        return 3
    atuais = cliente.status()
    if atuais != iniciais:
        informar("O inventário mudou após a confirmação. Nenhuma exclusão foi enviada. IDs atuais: " + apresentar_slots(atuais))
        return 1
    erros = []
    for slot in atuais:
        if slot == 0:
            continue
        try:
            cliente.excluir(slot)
            informar("Exclusão confirmada do ID " + str(slot) + ".")
        except KeyboardInterrupt:
            erros.append("Interrompido pelo usuário; a limpeza pode estar parcial.")
            break
        except Exception as erro:
            erros.append(str(erro))
            break
    try:
        restantes = cliente.status()
    except Exception as erro:
        erros.append("Não foi possível confirmar o estado final: " + str(erro))
        for mensagem in erros:
            informar("ERRO: " + mensagem)
        informar("Limpeza não comprovada. Reconecte e consulte o leitor novamente.")
        return 1
    informar("IDs remanescentes na nova consulta: " + apresentar_slots(restantes))
    for mensagem in erros:
        informar("ERRO: " + mensagem)
    if restantes or erros:
        informar("Limpeza incompleta ou com erro. Não considere a memória totalmente apagada.")
        return 1
    informar("Memória vazia confirmada por uma nova consulta ao leitor.")
    return 0


def main():
    parser = argparse.ArgumentParser(description="Remove digitais físicas usando o firmware USB atual do C.E.S.A.R.; não altera o banco MySQL.")
    parser.add_argument("--porta", required=True, help="Porta serial do ESP32, por exemplo COM5 ou /dev/ttyUSB0.")
    parser.add_argument("--timeout", type=float, default=12.0, help="Prazo por comando em segundos, entre 3 e 120; padrão 12.")
    args = parser.parse_args()
    if not 3 <= args.timeout <= 120:
        parser.error("--timeout deve ficar entre 3 e 120 segundos.")
    try:
        import serial
    except ImportError:
        print("Instale a dependência: py -m pip install pyserial==3.5", file=sys.stderr)
        return 2
    try:
        conexao = serial.Serial(port=None, baudrate=115200, bytesize=serial.EIGHTBITS,
                               parity=serial.PARITY_NONE, stopbits=serial.STOPBITS_ONE,
                               timeout=0.2, write_timeout=3)
        conexao.dtr = False
        conexao.rts = False
        conexao.port = args.porta
        try:
            conexao.open()
            time.sleep(2.5)
            conexao.reset_input_buffer()
            cliente = ClienteSerial(conexao, timeout=args.timeout)
            return limpar(cliente)
        finally:
            conexao.close()
    except (KeyboardInterrupt, EOFError):
        print("Interrompido. Se a exclusão já havia começado, a memória pode estar parcialmente apagada.", file=sys.stderr)
        return 1
    except Exception as erro:
        print("ERRO: " + str(erro), file=sys.stderr)
        print("Nenhum sucesso foi confirmado. Confira a porta, feche o site/Monitor Serial e tente consultar novamente.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
