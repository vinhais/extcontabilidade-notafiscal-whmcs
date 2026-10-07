# EXT Contabilidade - NotaFiscal para WHMCS

**O módulo ainda está em beta e em desenvolvimento.**

## Funcionalidades

- Geração automática de nota fiscal ao pagar a fatura, usando a API grátis e ilimitada da EXT Contabilidade.
- Conversão de outras moedas para BRL usando as taxas de câmbio do WHMCS.
- Emissão nacional de notas fiscais de prestação de serviços. A emissão para clientes estrangeiros ainda não está disponível no módulo.
- Logs das operações na tabela do módulo e no log de módulos do WHMCS.
- Cancelamento automático da nota fiscal em caso de reembolso ou cancelamento da fatura, dentro do prazo permitido pela EXT. Se a emissão ainda estiver em processamento, o cron solicita o cancelamento após a emissão.

## Instalação

1. Copie a pasta `modules` para a raiz da instalação do WHMCS.
2. No painel administrativo, acesse **Configurações > Configurações do sistema > Módulos adicionais** e ative **EXT Contabilidade - NotaFiscal**. Em **Controle de acesso**, libere os grupos de administradores que poderão usar o módulo; eles também precisam da permissão de gerenciar faturas.
3. Obtenha suas chaves no painel da EXT Contabilidade, em **Minha conta > API** ([Minhas chaves](https://extcontabilidade.com.br/app/user/my-api)), e preencha **Chave de API de produção** e/ou **Chave de API de desenvolvimento** nas configurações do módulo. A API precisa estar liberada para sua empresa pelo suporte da EXT.
4. Marque **Modo de produção** para usar a chave de produção (`ext_sk_live_...`). Desmarcado, o módulo usa a chave de desenvolvimento (`ext_sk_test_...`). As duas chaves podem ficar preenchidas; somente a chave do modo selecionado será usada.
5. No WHMCS, crie um campo personalizado de cliente do tipo texto com o nome exato **CPF/CNPJ** para armazenar o documento do cliente.
6. Configure o cron do WHMCS para executar a cada 5 minutos. O módulo usa esse cron para consultar o status das notas fiscais pendentes.

Documentação da API: [EXT Contabilidade Devs](https://extcontabilidade.com.br/devs/docs/).
