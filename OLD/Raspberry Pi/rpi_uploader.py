#!/usr/bin/env python3
import requests
import os
import time
import argparse
import json
import sys
from datetime import datetime

class RaspberryPiUploader:
    def __init__(self, api_url, api_key, verbose=False):
        self.api_url = api_url
        self.api_key = api_key
        self.verbose = verbose
        self.headers = {
            'X-API-KEY': api_key
        }
    
    def log(self, message):
        """Print log message if verbose mode is on"""
        if self.verbose:
            timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            print(f"[{timestamp}] {message}")
    
    def test_connection(self):
        """Test the API connection"""
        try:
            test_url = self.api_url.replace('/upload', '/test')
            response = requests.get(test_url, headers=self.headers, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                self.log(f"Conexão bem-sucedida: {data.get('message')}")
                self.log(f"Hora do servidor: {data.get('time')}")
                return True
            else:
                self.log(f"Erro na conexão: {response.status_code}")
                self.log(response.text)
                return False
        except Exception as e:
            self.log(f"Erro ao testar conexão: {str(e)}")
            return False
    
    def upload_file(self, file_path, metadata=None):
        """Upload a file to the WordPress API"""
        if not os.path.exists(file_path):
            self.log(f"Erro: O arquivo {file_path} não existe.")
            return False
        
        # Obter informações do arquivo
        file_size = os.path.getsize(file_path)
        file_name = os.path.basename(file_path)
        
        self.log(f"Enviando arquivo: {file_name} ({file_size} bytes)")
        
        # Criar o pacote multipart/form-data
        files = {
            'file': (file_name, open(file_path, 'rb'))
        }
        
        # Dados adicionais (opcional)
        data = metadata or {}
        data.update({
            'timestamp': datetime.now().isoformat(),
            'device': 'raspberry_pi',
            'filename': file_name,
            'filesize': file_size
        })
        
        try:
            # Iniciar o upload
            self.log("Iniciando upload...")
            start_time = time.time()
            
            response = requests.post(
                self.api_url,
                headers=self.headers,
                files=files,
                data=data,
                timeout=60
            )
            
            end_time = time.time()
            duration = end_time - start_time
            
            if response.status_code == 200 or response.status_code == 201:
                result = response.json()
                self.log(f"Upload bem-sucedido em {duration:.2f} segundos")
                self.log(f"URL do arquivo: {result.get('file_url')}")
                return True, result
            else:
                self.log(f"Erro no upload: {response.status_code}")
                self.log(response.text)
                return False, None
                
        except Exception as e:
            self.log(f"Exceção durante upload: {str(e)}")
            return False, None
        finally:
            files['file'][1].close()
    
    def monitor_directory(self, directory, interval=30, file_patterns=None, move_after=None):
        """
        Monitor a directory for new files and upload them
        
        Args:
            directory: Directory to monitor
            interval: Check interval in seconds
            file_patterns: List of file extensions to process (e.g. ['.jpg', '.png'])
            move_after: Directory to move files after successful upload
        """
        processed_files = set()
        
        self.log(f"Monitorando o diretório {directory} para novos arquivos...")
        if file_patterns:
            self.log(f"Filtrando por extensões: {', '.join(file_patterns)}")
        
        while True:
            try:
                for filename in os.listdir(directory):
                    file_path = os.path.join(directory, filename)
                    
                    # Verifica se é um arquivo e não foi processado
                    if os.path.isfile(file_path) and file_path not in processed_files:
                        # Verificar extensões, se fornecidas
                        if file_patterns and not any(filename.lower().endswith(ext.lower()) for ext in file_patterns):
                            continue
                        
                        self.log(f"Novo arquivo detectado: {filename}")
                        
                        success, result = self.upload_file(file_path)
                        if success:
                            processed_files.add(file_path)
                            
                            # Mover arquivo após upload, se configurado
                            if move_after and os.path.exists(move_after):
                                dest_path = os.path.join(move_after, filename)
                                os.rename(file_path, dest_path)
                                self.log(f"Arquivo movido para: {dest_path}")
            
            except Exception as e:
                self.log(f"Erro ao monitorar diretório: {str(e)}")
            
            time.sleep(interval)

def main():
    parser = argparse.ArgumentParser(description="Upload de arquivos do Raspberry Pi para WordPress")
    parser.add_argument("--file", help="Caminho do arquivo para upload")
    parser.add_argument("--monitor", help="Diretório para monitorar novos arquivos")
    parser.add_argument("--url", help="URL da API WordPress (ex: https://seu-site.com/wp-json/raspberry/v1/upload)")
    parser.add_argument("--key", help="Chave API para autenticação")
    parser.add_argument("--config", help="Arquivo de configuração JSON")
    parser.add_argument("--interval", type=int, default=30, help="Intervalo de verificação em segundos para o modo de monitoramento")
    parser.add_argument("--extensions", help="Lista de extensões de arquivo para monitorar (separadas por vírgula)")
    parser.add_argument("--move-to", help="Mover arquivos após upload para este diretório")
    parser.add_argument("--test", action="store_true", help="Testar conexão com a API")
    parser.add_argument("--verbose", action="store_true", help="Mostrar mensagens detalhadas")
    
    args = parser.parse_args()
    
    # Carregar configuração de arquivo se especificado
    config = {}
    if args.config and os.path.exists(args.config):
        with open(args.config, 'r') as f:
            config = json.load(f)
    
    # Priorizar argumentos da linha de comando sobre arquivo de configuração
    api_url = args.url or config.get('url')
    api_key = args.key or config.get('key')
    
    if not api_url or not api_key:
        print("Erro: URL da API e chave API são obrigatórios.")
        parser.print_help()
        sys.exit(1)
    
    # Inicializar uploader
    uploader = RaspberryPiUploader(api_url, api_key, args.verbose or config.get('verbose', False))
    
    # Testar conexão
    if args.test:
        if uploader.test_connection():
            print("Conexão com a API estabelecida com sucesso!")
            sys.exit(0)
        else:
            print("Falha ao conectar com a API.")
            sys.exit(1)
    
    # Processar extensões
    extensions = None
    if args.extensions:
        extensions = [ext.strip() for ext in args.extensions.split(',')]
    elif config.get('extensions'):
        extensions = config.get('extensions')
    
    # Upload de arquivo único
    if args.file:
        success, _ = uploader.upload_file(args.file)
        sys.exit(0 if success else 1)
    
    # Monitoramento de diretório
    elif args.monitor or config.get('monitor_directory'):
        monitor_dir = args.monitor or config.get('monitor_directory')
        interval = args.interval or config.get('check_interval', 30)
        move_to = args.move_to or config.get('move_to')
        
        if move_to and not os.path.exists(move_to):
            try:
                os.makedirs(move_to)
            except:
                print(f"Erro: Não foi possível criar o diretório {move_to}")
                sys.exit(1)
        
        uploader.monitor_directory(monitor_dir, interval, extensions, move_to)
    else:
        parser.print_help()

if __name__ == "__main__":
    main()