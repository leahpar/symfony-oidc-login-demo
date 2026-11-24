# Symfony 8.2 `oidc_login` avec Apple, Google et Pocket ID

Application minimale et notes d'intégration : connexion **OpenID Connect native** de Symfony 8.2
(`oidc_login`) avec trois fournisseurs à la fois, en HTTPS local.

Ce dépôt ne réécrit pas la documentation Symfony : il décrit **ce qu'il faut faire chez chaque
fournisseur**, la config qui a marché et les pièges rencontrés. Pour les options d'`oidc_login`, voir :

- [New in Symfony 8.2: OpenID Connect Login](https://symfony.com/blog/new-in-symfony-8-2-openid-connect-login)
- [Doc `oidc_login`](https://symfony.com/doc/8.2/security/oidc_login.html)
- [Doc Access Tokens](https://symfony.com/doc/8.2/security/access_token.html) (le pendant API : jetons bearer)

**Statut** : les trois connexions ont été validées en conditions réelles le 2026-11-24, sur Symfony
**8.2.0-BETA2** et PHP 8.5.

## Sommaire

1. [Démarrage](#démarrage)
2. [Plusieurs fournisseurs : l'architecture](#plusieurs-fournisseurs--larchitecture)
3. [Google](#google)
4. [Apple](#apple)
5. [Pocket ID](#pocket-id)
6. [HTTPS local](#https-local)
7. [Déconnexion](#déconnexion)
8. [Boutons de connexion](#boutons-de-connexion)
9. [À retenir pour une vraie application](#à-retenir-pour-une-vraie-application)

## Démarrage

```bash
composer install
```

```bash
cp .env .env.local   # puis renseigner les variables de chaque fournisseur (voir plus bas)
```

```bash
symfony serve --port=9443 --p12=var/certs/server.p12 --no-workers
```

Puis `https://localhost:9443/login` (ou `https://oidc-demo.example.com:9443/login` pour Apple, voir
[HTTPS local](#https-local)). La page `/moi` affiche les claims reçues et les attributs du jeton de
sécurité (jetons tronqués).

Paquets ajoutés au squelette : `symfony/security-bundle`, `symfony/http-client`,
`web-token/jwt-library`, `symfony/twig-bundle`. Au 2026-11-24, `symfony/skeleton` n'avait pas de tag
8.2 : projet créé en 8.1, puis `extra.symfony.require: "8.2.*"` et `minimum-stability: beta`.

## Plusieurs fournisseurs : l'architecture

`oidc_login` ne gère **qu'un fournisseur par firewall** (une seule `provider_uri`). Pour en proposer
trois :

- **un firewall par fournisseur** (`apple`, `google`, `pocketid`), restreint à `^/auth/<fournisseur>/`,
  avec son `start_path` (`/auth/<fournisseur>/start`) et son `check_path`
  (`/auth/<fournisseur>/callback`) ;
- **le même `context`** (`proto`) pour ces firewalls et pour `main` : le jeton posé par le firewall du
  fournisseur est relu par `main` sur tout le reste du site ;
- **`main` sans authenticator**, avec un `entry_point` maison
  ([`LoginEntryPoint`](src/Security/LoginEntryPoint.php)) qui envoie l'anonyme sur `/login`, la page
  qui liste les fournisseurs.

Le chargeur de routes `security.route_loader` (importé par la recette Flex) crée
`_oidc_login_start_<firewall>` (à lier depuis les boutons) et `_oidc_login_callback_<firewall>` (la
redirect URI, à ne jamais lier).

Utilisateur : [`ProtoUserProvider`](src/Security/ProtoUserProvider.php) implémente
`AttributesBasedUserProviderInterface` et reçoit le `sub` et toutes les claims. Une instance par
fournisseur ([`services.yaml`](config/services.yaml)) préfixe l'identifiant (`google:<sub>`), car deux
fournisseurs peuvent émettre le même `sub`. Ici rien n'est stocké : l'utilisateur vit dans la session.
Dans une vraie application, c'est là qu'on cherche (ou crée) l'identité `(fournisseur, sub)`.

Config complète : [`config/packages/security.yaml`](config/packages/security.yaml).

## Google

**Côté Google Cloud Console** :

1. Sur <https://console.cloud.google.com/>, sélecteur de projet → **Nouveau projet** (sans
   organisation), puis vérifier qu'il est bien sélectionné.
2. **API et services → Écran de consentement OAuth** (« Google Auth Platform » → **Commencer**) :
   - informations sur l'application : nom affiché à l'utilisateur, email d'assistance ;
   - cible : **Externe** (comptes Gmail ordinaires, hors Google Workspace) ;
   - coordonnées, acceptation des conditions → **Créer** ;
   - **Cible (Audience)** : en mode **Test**, seuls les comptes listés dans **Utilisateurs test**
     peuvent se connecter ; publier l'application pour l'ouvrir à tous ;
   - **Accès aux données** : rien à ajouter, `openid`, `email` et `profile` sont des scopes non
     sensibles (pas de validation Google, même une fois publiée).
3. **Clients** (ou Identifiants → Créer des identifiants → ID client OAuth) :
   - type **Application Web** ;
   - origines JavaScript autorisées : aucune (flux côté serveur) ;
   - **URI de redirection autorisés** : `https://localhost:9443/auth/google/callback` (Google accepte
     `localhost` comme exception à la règle du domaine public) ;
   - **Créer** : copier l'ID client (`….apps.googleusercontent.com`) et le **code secret**, qui n'est
     plus affiché en entier ensuite (sinon en régénérer un).

**Erreurs fréquentes** :

- `redirect_uri_mismatch` : l'URI doit correspondre au caractère près (schéma, port, pas de `/`
  final) ; une modification peut mettre quelques minutes à être prise en compte ;
- `access_denied` / « application en cours de test » : le compte n'est pas dans les utilisateurs test.

**Variables** : `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`.

**Config** : `provider_uri: https://accounts.google.com`, `client_secret_basic`,
`scope: [openid, profile, email]`. Le reste par défaut (claims via UserInfo, RS256, PKCE S256).

**Claims reçues** (UserInfo) : `sub`, `email`, `email_verified`, `name`, `given_name`,
`family_name`, `picture`. Pas de refresh token sans le scope `offline_access`.

## Apple

C'est le fournisseur le plus exigeant.

**Côté Apple Developer** (compte payant requis) :

Tout se passe sur <https://developer.apple.com/account>, rubrique **Certificates, IDs & Profiles**.

1. **Identifiers → + → App IDs**, type **App** :
   - description libre, Bundle ID **Explicit** (ex. `fr.exemple.app`) ;
   - dans Capabilities, cocher **Sign in with Apple** (laisser « Enable as a primary App ID ») ;
   - **Continue → Register**.
2. **Identifiers → + → Services IDs** : son identifiant (ex. `fr.exemple.web`) est le **`client_id`**,
   et sa description est le nom affiché à l'utilisateur pendant la connexion.
   - **Register**, puis rouvrir le Services ID, cocher **Sign in with Apple** → **Configure** ;
   - Primary App ID : celui de l'étape 1 ;
   - **Domains and Subdomains** : `oidc-demo.example.com` ;
   - **Return URLs** : `https://oidc-demo.example.com:9443/auth/apple/callback`. Apple **refuse
     `localhost` et les IP**, mais **accepte un port** ;
   - **Next → Done → Continue → Save**.
3. **Keys → +** : nom libre, cocher **Sign in with Apple** → **Configure** → choisir l'App ID de
   l'étape 1 → **Save → Continue → Register → Download**. Le fichier `AuthKey_<KEYID>.p8` ne se
   télécharge **qu'une fois** : le ranger hors du dépôt versionné (ici `var/certs/`, `chmod 600`).
   Noter son **Key ID** (10 caractères, sur la page de la clé).
4. Le **Team ID** est affiché en haut à droite du portail (ou dans *Membership details*).

L'hôte de la Return URL doit pointer sur le poste : `127.0.0.1 oidc-demo.example.com` dans
`/etc/hosts`, et la connexion se lance depuis `https://oidc-demo.example.com:9443/login` (la redirect
URI est déduite de l'hôte de la requête).

**Secret client** : chez Apple, c'est un **JWT ES256** signé avec la clé `.p8`, valable **6 mois au
plus**. La commande [`app:apple:client-secret`](src/Command/AppleClientSecretCommand.php) le génère :

```bash
bin/console app:apple:client-secret
```

Elle lit `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_CLIENT_ID` et `APPLE_PRIVATE_KEY_PATH` (**chemin
absolu** : `%kernel.project_dir%` n'est pas résolu dans un `.env`), et affiche le JWT à mettre dans
`APPLE_CLIENT_SECRET`. **À régénérer avant expiration** (date affichée par la commande).

**Config** :

- `provider_uri: https://appleid.apple.com`, `client_secret_post` avec le JWT ;
- `scope: [openid, name, email]`, qui impose `authorization_params: { response_mode: form_post }` :
  Apple renvoie le code par un **POST cross-site** sur le callback, qu'`oidc_login` gère nativement ;
- d'où le cookie de session en **`SameSite=None; Secure`**
  ([`framework.yaml`](config/packages/framework.yaml)) : sans ça, le cookie n'accompagne pas le POST,
  le `state` est introuvable et la connexion échoue ;
- `user_data_source: id_token` : Apple n'a **pas d'endpoint UserInfo** ;
- PKCE S256 laissé activé : Apple ne le documente pas mais l'accepte.

**Claims reçues** (ID token) : `sub`, `email`, `email_verified`, `is_private_email`, `iss`, `aud`,
`exp`, `iat`, `nonce`, `auth_time`. **Ni nom ni prénom.**

**Pièges** :

- **Le nom n'arrive qu'une fois**, à la toute première autorisation, dans le champ POST `user` (JSON
  avec prénom, nom, email) du callback, jamais dans l'ID token. `oidc_login` l'ignore :
  [`AppleUserCaptureListener`](src/EventListener/AppleUserCaptureListener.php) le met en session avant
  le firewall. Ce champ n'est **pas signé** : à prendre comme une suggestion. Pour le revoir, retirer
  l'application dans les réglages du compte Apple (« Se connecter avec Apple »).
- Erreurs probables au premier essai : `invalid_client` (JWT, Team ID ou Key ID incorrects, ou JWT
  expiré) ; `invalid_grant` (code déjà utilisé ou expiré, ou redirect URI différente de celle de
  l'autorisation).
- Avec « Masquer mon adresse », l'email est un relais `@privaterelay.appleid.com` : ne pas s'en servir
  comme identifiant.

## Pocket ID

[Pocket ID](https://pocket-id.org) : fournisseur OIDC auto-hébergé (passkeys).

**Côté Pocket ID** (admin → OIDC Clients) :

1. Créer un client, **Callback URLs** : `https://localhost:9443/auth/pocketid/callback`. Attention, le
   champ voisin **Logout Callback URLs** ne sert qu'à la déconnexion : y mettre l'URL par erreur donne
   « redirect_uri … is not registered for this client ».
2. Le **client secret n'est affiché qu'une fois**, à la création (sinon, le régénérer).

**Variables** : `POCKETID_URI` = **racine de l'instance** (l'issuer, sans `/` final), pas l'URL de
callback (sinon 404 sur la discovery) ; `POCKETID_CLIENT_ID`, `POCKETID_CLIENT_SECRET`.

**Config** : `client_secret_basic`, `scope: [openid, profile, email, groups]`. ID tokens signés en
RS256 (défaut accepté).

**Claims reçues** (UserInfo) : `sub`, `name`, `display_name`, `given_name`, `family_name`,
`preferred_username`, `email`, `email_verified`, `picture`, `groups` (pratique pour attribuer des rôles).

## HTTPS local

Symfony CLI avec un certificat [mkcert](https://github.com/FiloSottile/mkcert) valable pour
`localhost`, `127.0.0.1` et `oidc-demo.example.com` (`var/certs/`, non versionné) :

```bash
mkcert -cert-file var/certs/server.pem -key-file var/certs/server-key.pem localhost 127.0.0.1 oidc-demo.example.com
```

```bash
openssl pkcs12 -export -in var/certs/server.pem -inkey var/certs/server-key.pem -out var/certs/server.p12 -passout pass:
```

- `symfony serve --p12` attend un p12 **sans mot de passe** : celui produit par `mkcert -pkcs12`
  (mot de passe « changeit ») est refusé.
- **Port fixe** : il fait partie des redirect URI enregistrées chez les fournisseurs.
- Pour Apple : `127.0.0.1 oidc-demo.example.com` dans `/etc/hosts`, et se connecter depuis cet hôte.
  `oidc_login` construit la redirect URI à partir de l'hôte de la requête.

## Déconnexion

Déconnexion **locale seulement** (`logout` sur `main`) : la session Symfony est fermée, pas celle du
fournisseur.

- Google et Apple n'annoncent pas d'`end_session_endpoint` : pas de déconnexion côté fournisseur
  possible.
- Pocket ID en annonce un. Mais l'option `enable_end_session` branche son listener sur le dispatcher
  **du firewall du fournisseur** : un `logout` déclaré sur `main` ne le déclenche pas. Il faudrait un
  `logout` dans le firewall `pocketid` et choisir le lien de déconnexion selon le fournisseur. Choix
  fait ici : ne pas le faire.

## Boutons de connexion

[`templates/_boutons_oidc.html.twig`](templates/_boutons_oidc.html.twig) et
[`templates/logos/`](templates/logos/) : boutons et logos repris du projet letmecount. Les trois boutons
ont le même style : blanc, contour gris, logo à gauche. Pour Apple, c'est la variante « blanc avec
contour », l'un des trois styles autorisés par ses
[consignes](https://developer.apple.com/design/human-interface-guidelines/sign-in-with-apple) (Apple
exige aussi un bouton au moins aussi visible que les autres). Logo Google officiel multicolore ; logos
Apple et Pocket ID monochromes (`currentColor`).

## À retenir pour une vraie application

- Un firewall par fournisseur dans un `context` commun ; un user provider
  `AttributesBasedUserProviderInterface` qui retrouve l'identité par `(fournisseur, sub)`, jamais par
  email (relais Apple, email modifiable chez le fournisseur).
- Enregistrer le nom Apple dès la première connexion, ou le faire saisir.
- Secret Apple : prévoir sa régénération tous les 6 mois (rappel, ou tâche planifiée).
- Cookie de session `SameSite=None; Secure` à cause d'Apple : penser à la protection CSRF des
  formulaires (active par défaut avec les formulaires Symfony).
- Mettre GMP ou BCMath en production : sans eux, `web-token/jwt-library` calcule en PHP pur (lent).
