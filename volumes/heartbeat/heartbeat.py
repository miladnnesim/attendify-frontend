import socket
import time
import logging
import os
import xml.etree.ElementTree as ET
import xml.dom.minidom  # Voor het mooi maken van de XML
import json
import pika
from datetime import datetime

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(message)s')

# Docker socket
DOCKER_SOCKET = '/var/run/docker.sock'

# RabbitMQ connection parameters
RABBITMQ_HOST = 'rabbitmq'
RABBITMQ_PORT = 5672
RABBITMQ_USERNAME = 'attendify'
RABBITMQ_PASSWORD = os.environ.get('RABBITMQ_PASSWORD', 'uXe5u1oWkh32JyLA')  # Default voor testen
RABBITMQ_VHOST = 'attendify'

# Heartbeat-specific parameters

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
    ('attendify-frontend-consumer-payment-1', 80)

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
        client.settimeout(2)

        response = b''
        try:
            while True:
                chunk = client.recv(4096)
                if not chunk:
                    break
                response += chunk
        except socket.timeout:
            pass

        response = response.decode('utf-8')

        parts = response.split('\r\n\r\n', 1)
        if len(parts) != 2:
            logging.error(f"Invalid HTTP response from Docker when checking {container_name}")
            return False
        headers, body = parts

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

def create_heartbeat_message(container_name):
    """Maak een heartbeat XML bericht volgens XSD schema met containernaam als sender"""
    root = ET.Element('heartbeat')
    ET.SubElement(root, 'sender').text = container_name
    timestamp = datetime.utcnow().isoformat() + 'Z'
    ET.SubElement(root, 'timestamp').text = timestamp

    rough_string = ET.tostring(root, encoding='utf-8', method='xml')
    reparsed = xml.dom.minidom.parseString(rough_string)
    pretty_xml = reparsed.toprettyxml(indent="  ")

    # Verwijder de eerste regel met XML declaration
    pretty_xml_no_header = '\n'.join(pretty_xml.split('\n')[1:]).strip()
    return pretty_xml_no_header.encode('utf-8')  # encode opnieuw naar bytes



def main():
    credentials = pika.PlainCredentials(username=RABBITMQ_USERNAME, password=RABBITMQ_PASSWORD)

    # Retry RabbitMQ verbinding (max 5 keer proberen, telkens 5 seconden wachten)
    for i in range(5):
        try:
            connection = pika.BlockingConnection(
                pika.ConnectionParameters(
                    host=RABBITMQ_HOST,
                    port=RABBITMQ_PORT,
                    credentials=credentials,
                    virtual_host=RABBITMQ_VHOST
                )
            )
            break  # Verbinding gelukt
        except Exception as e:
            print(f"RabbitMQ verbinding mislukt ({i+1}/5): {e}")
            time.sleep(5)
    else:
        print("RabbitMQ is niet bereikbaar na 5 pogingen. Script stopt.")
        return

    channel = connection.channel()

    logging.info(f"Starting heartbeat monitor for services: {[service[0] for service in SERVICES]}")

    try:
        while True:
            all_running = True
            for container_name, port in SERVICES:
                status = check_service_status(container_name)
                if not status:
                    all_running = False
                color = GREEN if status else RED
                status_text = f"{color}{'UP' if status else 'DOWN'}{RESET}"
                logging.info(f"Heartbeat check for {container_name} - Status: {status_text}")

                if status:
                    message = create_heartbeat_message(container_name)
                    channel.basic_publish(
                        exchange=EXCHANGE_NAME,
                        routing_key=ROUTING_KEY,
                        body=message,
                        properties=pika.BasicProperties(delivery_mode=2)
                    )

            if not all_running:
                logging.info(f"{RED}Skipping heartbeat send: one or more containers DOWN{RESET}")

            time.sleep(1)
    except KeyboardInterrupt:
        logging.info("Heartbeat monitor gestopt door gebruiker")
    finally:
        connection.close()


if __name__ == "__main__":
    main()