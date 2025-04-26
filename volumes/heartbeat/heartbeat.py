import socket
import time
import logging
import xml.etree.ElementTree as ET
from datetime import datetime
import xml.dom.minidom
import json

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(message)s')

# Docker socket
DOCKER_SOCKET = '/var/run/docker.sock'

# Heartbeat-specific parameters
SENDER = 'Frontend'
EXCHANGE_NAME = 'monitoring'
ROUTING_KEY = 'monitoring.heartbeat'

# ANSI kleuren
GREEN = '\033[92m'
RED = '\033[91m'
RESET = '\033[0m'

# Lijst van containers om te monitoren (service naam + poort)
SERVICES = [
    ('attendify-frontend-wordpress-1', 80),
    ('attendify-frontend-db-1', 3306),
    ('attendify-frontend-phpmyadmin-1', 80),
    ('attendify-frontend-consumer-user-1', 80),
]

def check_service_status(container_name):
    """Check de status van de container via Docker API"""
    try:
        client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        client.connect(DOCKER_SOCKET)

        endpoint = f'/containers/{container_name}/json'
        request = (
            f"GET {endpoint} HTTP/1.1\r\n"
            f"Host: localhost\r\n"
            f"Connection: close\r\n"
            f"\r\n"
        )

        client.sendall(request.encode())
        client.settimeout(2)  # Max 2 seconden wachten per recv()

        response = b''
        try:
            while True:
                chunk = client.recv(4096)
                if not chunk:
                    break
                response += chunk
        except socket.timeout:
            pass  # Klaar met lezen na timeout

        response = response.decode('utf-8')

        # Split headers en body
        parts = response.split('\r\n\r\n', 1)
        if len(parts) != 2:
            logging.error(f"Invalid HTTP response from Docker when checking {container_name}")
            return False
        headers, body = parts

        # Zoek eerste { en laatste }
        start = body.find('{')
        end = body.rfind('}') + 1
        json_data = body[start:end]

        container_info = json.loads(json_data)

        if container_info.get('State', {}).get('Status') == 'running':
            return True
        return False

    except Exception as e:
        logging.error(f"Error checking service status for {container_name}: {e}")
        return False
    finally:
        client.close()

def create_heartbeat_message(container_name, status):
    """Maak een heartbeat XML bericht volgens de opgegeven XSD"""
    root = ET.Element('heartbeat')
    
    sender_elem = ET.SubElement(root, 'sender')
    sender_elem.text = SENDER

    timestamp_elem = ET.SubElement(root, 'timestamp')
    timestamp_elem.text = str(int(time.time() * 1000))  # Milliseconds since epoch als long

    rough_string = ET.tostring(root, 'utf-8')
    reparsed = xml.dom.minidom.parseString(rough_string)
    pretty_xml = reparsed.toprettyxml(indent="  ")
    return pretty_xml.encode('utf-8')  # Encode naar bytes voor RabbitMQ

def main():
    logging.info(f"Starting heartbeat monitor for services: {[service[0] for service in SERVICES]}")

    try:
        while True:
            for container_name, port in SERVICES:
                status = check_service_status(container_name)
                message = create_heartbeat_message(container_name, status)
                
                color = GREEN if status else RED
                status_text = f"{color}{'UP' if status else 'DOWN'}{RESET}"
                logging.info(f"Heartbeat sent for {container_name} - Status: {status_text}")
                
                # Hier zou je het message naar RabbitMQ sturen als je wilt
                # Bijvoorbeeld: channel.basic_publish(exchange=EXCHANGE_NAME, routing_key=ROUTING_KEY, body=message)
                
            time.sleep(1)  # Elke 1 seconde checken
    except KeyboardInterrupt:
        logging.info("Heartbeat monitor gestopt door gebruiker")

if __name__ == "__main__":
    main()
