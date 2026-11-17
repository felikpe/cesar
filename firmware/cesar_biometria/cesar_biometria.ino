#include <Arduino.h>
#include <ArduinoJson.h>
#include <Adafruit_Fingerprint.h>

#ifndef CESAR_SENSOR_RX
#define CESAR_SENSOR_RX 16
#endif
#ifndef CESAR_SENSOR_TX
#define CESAR_SENSOR_TX 17
#endif
#ifndef CESAR_SENSOR_BAUD
#define CESAR_SENSOR_BAUD 57600
#endif
#ifndef CESAR_SENSOR_PASSWORD
#define CESAR_SENSOR_PASSWORD 0x00000000
#endif

constexpr uint16_t MAX_SLOTS = 1024;
constexpr uint32_t USB_BAUD = 115200;
constexpr uint32_t SCAN_TIMEOUT_MS = 20000;
constexpr uint32_t ENROLL_TIMEOUT_MS = 60000;
constexpr uint32_t POLL_INTERVAL_MS = 120;
constexpr size_t MAX_LINE = 512;

HardwareSerial sensorSerial(2);
Adafruit_Fingerprint fingerprint(static_cast<Stream *>(&sensorSerial), CESAR_SENSOR_PASSWORD);

bool occupied[MAX_SLOTS] = {};
bool sensorReady = false;
bool inventoryReady = false;
uint16_t sensorCapacity = 0;
String serialLine;
bool discardingLine = false;
String deviceId;

enum class Stage { IDLE, SCAN, FIRST_CAPTURE, REMOVE_FINGER, SECOND_CAPTURE };
Stage stage = Stage::IDLE;
String activeRequest;
String activeCommand;
uint16_t activeSlot = 0;
uint32_t operationStarted = 0;
uint32_t nextPoll = 0;
uint32_t operationGeneration = 0;

void sendMessage(const String &request, const String &command, const char *event,
                 bool ok, const char *code, const String &message, int slot = -1,
                 int confidence = -1, bool includeInventory = false) {
  JsonDocument response;
  response["v"] = 1;
  response["request"] = request;
  response["cmd"] = command;
  response["event"] = event;
  response["ok"] = ok;
  response["code"] = code;
  response["message"] = message;
  response["device"] = deviceId;
  response["firmware"] = "1.0.0";
  response["sensor"] = sensorReady;
  response["capacity"] = sensorCapacity;
  response["inventory"] = inventoryReady;
  if (slot >= 0) response["slot"] = slot;
  if (confidence >= 0) response["confidence"] = confidence;
  if (includeInventory && inventoryReady) {
    JsonArray slots = response["occupied"].to<JsonArray>();
    for (uint16_t i = 0; i < sensorCapacity; ++i) {
      if (occupied[i]) slots.add(i);
    }
  }
  serializeJson(response, Serial);
  Serial.println();
}

void finishOperation(bool ok, const char *code, const String &message,
                     int slot = -1, int confidence = -1) {
  sendMessage(activeRequest, activeCommand, "result", ok, code, message, slot, confidence);
  stage = Stage::IDLE;
  ++operationGeneration;
  activeRequest = "";
  activeCommand = "";
  activeSlot = 0;
}

void progress(const char *code, const String &message) {
  sendMessage(activeRequest, activeCommand, "progress", true, code, message,
              activeSlot > 0 ? activeSlot : -1);
}

String sensorError(uint8_t status) {
  switch (status) {
    case FINGERPRINT_PACKETRECIEVEERR:
    case FINGERPRINT_TIMEOUT:
    case FINGERPRINT_BADPACKET:
      sensorReady = false;
      inventoryReady = false;
      return "Falha de comunicação com o leitor. Confira TX, RX, alimentação e baud rate.";
    case FINGERPRINT_IMAGEMESS:
    case FINGERPRINT_FEATUREFAIL:
    case FINGERPRINT_INVALIDIMAGE:
    case FINGERPRINT_IMAGEFAIL:
      return "Não foi possível ler a digital. Limpe o leitor e tente novamente.";
    case FINGERPRINT_ENROLLMISMATCH:
      return "As duas leituras são diferentes. Repita usando o mesmo dedo.";
    default:
      return "O leitor recusou a operação (código " + String(status) + ").";
  }
}

bool readInventory() {
  inventoryReady = false;
  if (!sensorReady || sensorCapacity < 2 || sensorCapacity > MAX_SLOTS) return false;
  memset(occupied, 0, sizeof(occupied));
  uint16_t counted = 0;
  for (uint16_t base = 0; base < sensorCapacity; base += 256) {
    uint8_t payload[] = {0x1F, static_cast<uint8_t>(base / 256)};
    Adafruit_Fingerprint_Packet packet(FINGERPRINT_COMMANDPACKET, sizeof(payload), payload);
    fingerprint.writeStructuredPacket(packet);
    if (fingerprint.getStructuredPacket(&packet, 1000) != FINGERPRINT_OK ||
        packet.type != FINGERPRINT_ACKPACKET || packet.length != 35 ||
        packet.data[0] != FINGERPRINT_OK) return false;
    uint16_t checksum = packet.type + (packet.length >> 8) + (packet.length & 0xFF);
    for (uint8_t i = 0; i < 33; ++i) checksum += packet.data[i];
    uint16_t received = (static_cast<uint16_t>(packet.data[33]) << 8) | packet.data[34];
    if (checksum != received) return false;
    for (uint16_t bit = 0; bit < 256 && base + bit < sensorCapacity; ++bit) {
      occupied[base + bit] = (packet.data[1 + bit / 8] & (1U << (bit % 8))) != 0;
      if (occupied[base + bit]) ++counted;
    }
  }
  if (fingerprint.getTemplateCount() != FINGERPRINT_OK || fingerprint.templateCount != counted) return false;
  inventoryReady = true;
  return true;
}

bool connectSensor() {
  sensorReady = false;
  inventoryReady = false;
  sensorCapacity = 0;
  while (sensorSerial.available()) sensorSerial.read();
  if (!fingerprint.verifyPassword()) return false;
  if (fingerprint.getParameters() != FINGERPRINT_OK) return false;
  sensorReady = true;
  sensorCapacity = fingerprint.capacity;
  return true;
}

bool validRequest(const char *value) {
  if (value == nullptr) return false;
  size_t length = strlen(value);
  if (length < 1 || length > 64) return false;
  for (size_t i = 0; i < length; ++i) {
    char c = value[i];
    if (!isxdigit(static_cast<unsigned char>(c)) && c != '-') return false;
  }
  return true;
}

void handleCommand(const String &line) {
  JsonDocument request;
  if (deserializeJson(request, line) != DeserializationError::Ok || !request.is<JsonObject>()) {
    sendMessage("", "", "result", false, "invalid_json", "Comando JSON inválido.");
    return;
  }
  const char *id = request["request"];
  const char *command = request["cmd"];
  if (!request["v"].is<int>() || request["v"].as<int>() != 1 ||
      !validRequest(id) || command == nullptr) {
    sendMessage("", "", "result", false, "invalid_command", "Versão, comando ou identificador inválido.");
    return;
  }
  String requestId(id);
  String cmd(command);
  if (cmd == "cancel") {
    if (stage != Stage::IDLE) finishOperation(false, "cancelled", "Operação cancelada.");
    sendMessage(requestId, cmd, "result", true, "cancelled", "Leitor pronto para outra operação.");
    return;
  }
  if (stage != Stage::IDLE) {
    sendMessage(requestId, cmd, "result", false, "busy", "Já existe uma operação em andamento.");
    return;
  }
  if (cmd != "status" && cmd != "scan" && cmd != "enroll" && cmd != "delete") {
    sendMessage(requestId, cmd, "result", false, "unknown_command", "Comando não reconhecido.");
    return;
  }
  if (!connectSensor()) {
    sendMessage(requestId, cmd, "result", false, "sensor_offline", "Leitor não respondeu. Confira UART, alimentação e baud rate.");
    return;
  }
  if (sensorCapacity < 2 || sensorCapacity > MAX_SLOTS) {
    sendMessage(requestId, cmd, "result", false, "capacity_unsupported", "Capacidade do leitor fora do intervalo suportado.");
    return;
  }
  if (cmd == "status") {
    if (!readInventory()) {
      sendMessage(requestId, cmd, "result", false, "inventory_unavailable", "Leitor respondeu, mas não foi possível consultar a memória de digitais.");
      return;
    }
    sendMessage(requestId, cmd, "result", true, "ready", "Leitor conectado.", -1, -1, true);
    return;
  }
  if (cmd == "enroll" || cmd == "delete") {
    if (!request["slot"].is<unsigned int>() || request["slot"].as<unsigned int>() < 1 ||
        request["slot"].as<unsigned int>() >= sensorCapacity) {
      sendMessage(requestId, cmd, "result", false, "invalid_slot", "ID biométrico fora da capacidade do leitor.");
      return;
    }
    if (!readInventory()) {
      sendMessage(requestId, cmd, "result", false, "inventory_unavailable", "Operação bloqueada: não foi possível consultar os IDs ocupados.");
      return;
    }
    uint16_t slot = request["slot"].as<uint16_t>();
    if (cmd == "delete") {
      if (!request["confirm"].is<bool>() || !request["confirm"].as<bool>()) {
        sendMessage(requestId, cmd, "result", false, "confirmation_required", "Confirme a exclusão específica desta digital.", slot);
        return;
      }
      if (!occupied[slot]) {
        sendMessage(requestId, cmd, "result", false, "slot_empty", "O ID informado não contém uma digital.", slot);
        return;
      }
      uint8_t status = fingerprint.deleteModel(slot);
      if (status != FINGERPRINT_OK) {
        sendMessage(requestId, cmd, "result", false, "delete_failed", sensorError(status), slot);
        return;
      }
      occupied[slot] = false;
      sendMessage(requestId, cmd, "result", true, "deleted", "Digital removida do leitor.", slot);
      return;
    }
    if (occupied[slot]) {
      sendMessage(requestId, cmd, "result", false, "slot_occupied", "Esse ID já contém uma digital. Escolha outro ID livre.", slot);
      return;
    }
    activeSlot = slot;
  }
  ++operationGeneration;
  activeRequest = requestId;
  activeCommand = cmd;
  operationStarted = millis();
  nextPoll = millis();
  stage = cmd == "scan" ? Stage::SCAN : Stage::FIRST_CAPTURE;
  progress("place_finger", "Coloque o dedo no leitor.");
}

void readUsb() {
  while (Serial.available()) {
    char c = static_cast<char>(Serial.read());
    if (c == '\r') continue;
    if (c == '\n') {
      if (discardingLine) {
        sendMessage("", "", "result", false, "line_too_long", "Comando excede o tamanho permitido.");
      } else if (serialLine.length() > 0) {
        handleCommand(serialLine);
      }
      serialLine = "";
      discardingLine = false;
    } else if (!discardingLine) {
      if (serialLine.length() >= MAX_LINE) {
        discardingLine = true;
        serialLine = "";
      } else {
        serialLine += c;
      }
    }
  }
}

bool continueOperation(uint32_t generation) {
  readUsb();
  if (stage == Stage::IDLE || operationGeneration != generation) return false;
  uint32_t timeout = activeCommand == "enroll" ? ENROLL_TIMEOUT_MS : SCAN_TIMEOUT_MS;
  if (static_cast<uint32_t>(millis() - operationStarted) >= timeout) {
    finishOperation(false, "timeout", "Tempo esgotado. Inicie uma nova leitura.");
    return false;
  }
  return true;
}

void processOperation() {
  if (stage == Stage::IDLE) return;
  uint32_t timeout = activeCommand == "enroll" ? ENROLL_TIMEOUT_MS : SCAN_TIMEOUT_MS;
  if (static_cast<uint32_t>(millis() - operationStarted) >= timeout) {
    finishOperation(false, "timeout", "Tempo esgotado. Inicie uma nova leitura.");
    return;
  }
  if (static_cast<int32_t>(millis() - nextPoll) < 0) return;
  nextPoll = millis() + POLL_INTERVAL_MS;
  uint32_t generation = operationGeneration;
  uint8_t status = fingerprint.getImage();
  if (!continueOperation(generation)) return;
  if (stage == Stage::REMOVE_FINGER) {
    if (status == FINGERPRINT_NOFINGER) {
      stage = Stage::SECOND_CAPTURE;
      progress("place_same_finger", "Coloque novamente o mesmo dedo no leitor.");
    } else if (status != FINGERPRINT_OK) {
      finishOperation(false, "sensor_error", sensorError(status));
    }
    return;
  }
  if (status == FINGERPRINT_NOFINGER) return;
  if (status != FINGERPRINT_OK) {
    finishOperation(false, "sensor_error", sensorError(status));
    return;
  }
  uint8_t bufferId = stage == Stage::SECOND_CAPTURE ? 2 : 1;
  status = fingerprint.image2Tz(bufferId);
  if (!continueOperation(generation)) return;
  if (status != FINGERPRINT_OK) {
    finishOperation(false, "image_error", sensorError(status));
    return;
  }
  if (stage == Stage::SCAN || stage == Stage::FIRST_CAPTURE) {
    status = fingerprint.fingerSearch(1);
    if (!continueOperation(generation)) return;
    if (stage == Stage::SCAN) {
      if (status == FINGERPRINT_NOTFOUND) {
        finishOperation(false, "not_found", "Digital não cadastrada no leitor.");
      } else if (status == FINGERPRINT_OK && fingerprint.fingerID < sensorCapacity && fingerprint.fingerID > 0) {
        finishOperation(true, "matched", "Digital reconhecida.", fingerprint.fingerID, fingerprint.confidence);
      } else {
        finishOperation(false, "search_error", sensorError(status));
      }
      return;
    }
    if (status == FINGERPRINT_OK) {
      finishOperation(false, "duplicate_finger", "Esta digital já existe no leitor.", fingerprint.fingerID, fingerprint.confidence);
      return;
    }
    if (status != FINGERPRINT_NOTFOUND) {
      finishOperation(false, "search_error", sensorError(status));
      return;
    }
    stage = Stage::REMOVE_FINGER;
    progress("remove_finger", "Retire completamente o dedo do leitor.");
    return;
  }
  status = fingerprint.createModel();
  if (!continueOperation(generation)) return;
  if (status != FINGERPRINT_OK) {
    finishOperation(false, "enroll_mismatch", sensorError(status));
    return;
  }
  bool inventoryValid = readInventory();
  if (!continueOperation(generation)) return;
  if (!inventoryValid) {
    finishOperation(false, "inventory_unavailable", "Cadastro cancelado: não foi possível verificar os IDs ocupados.");
    return;
  }
  if (occupied[activeSlot]) {
    finishOperation(false, "slot_occupied", "Cadastro cancelado: o ID já está ocupado.", activeSlot);
    return;
  }
  status = fingerprint.storeModel(activeSlot);
  if (status != FINGERPRINT_OK) {
    finishOperation(false, "store_failed", sensorError(status));
    return;
  }
  occupied[activeSlot] = true;
  finishOperation(true, "enrolled", "Digital cadastrada no leitor.", activeSlot);
}

void setup() {
  Serial.begin(USB_BAUD);
  serialLine.reserve(MAX_LINE);
  char device[17];
  snprintf(device, sizeof(device), "%012llX", static_cast<unsigned long long>(ESP.getEfuseMac()));
  deviceId = device;
  sensorSerial.begin(CESAR_SENSOR_BAUD, SERIAL_8N1, CESAR_SENSOR_RX, CESAR_SENSOR_TX);
  fingerprint.begin(CESAR_SENSOR_BAUD);
}

void loop() {
  readUsb();
  processOperation();
  delay(1);
}
