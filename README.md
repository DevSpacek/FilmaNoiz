# FilmaNois Plugin

Este é um plugin para WordPress que permite o upload de arquivos do Raspberry Pi para a biblioteca de mídia do WordPress.

## Funcionalidades

- Adicionar uma página de configurações no painel administrativo do WordPress.
- Configurar a chave API para autenticação.
- Adicionar arquivos enviados à biblioteca de mídia do WordPress.
- Validar tipos MIME dos arquivos enviados.
- Habilitar e visualizar logs de upload.

## Instalação

1. Faça o download do plugin.
2. Extraia o conteúdo do arquivo zip na pasta `wp-content/plugins` do seu WordPress.
3. Ative o plugin através do menu "Plugins" no WordPress.

## Configuração

1. Vá para "Configurações" > "RPi Uploader" no painel administrativo do WordPress.
2. Configure a chave API, opções de mídia, validação MIME e logs conforme necessário.
3. Use os endpoints fornecidos para realizar uploads a partir do Raspberry Pi.

## Uso

### Exemplo de uso com Python no Raspberry Pi

```python
import requests

api_url = "http://seu-site.com/wp-json/raspberry/v1/upload"
api_key = "SUA_CHAVE_API"

headers = {
    "X-API-KEY": api_key
}

files = {
    "file": ("image.jpg", open("caminho/para/seu/arquivo.jpg", "rb"))
}

response = requests.post(api_url, headers=headers, files=files)
print(response.json())
```

## Logs

Se a opção de logs estiver habilitada, você poderá visualizar os logs de upload na página de configurações do plugin. Também é possível limpar os logs através do botão "Limpar Logs".

## Suporte

Para suporte, entre em contato com o desenvolvedor ou abra uma issue no repositório do projeto.
