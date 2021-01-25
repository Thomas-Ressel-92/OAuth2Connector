<?php
namespace axenox\OAuth2Connector\CommonLogic\Security\Authenticators;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use axenox\OAuth2Connector\Facades\OAuth2ClientFacade;
use exface\Core\Factories\FacadeFactory;
use axenox\OAuth2Connector\CommonLogic\Security\AuthenticationToken\OAuth2AuthenticatedToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use axenox\OAuth2Connector\CommonLogic\Security\AuthenticationToken\OAuth2RequestToken;
use League\OAuth2\Client\Provider\AbstractProvider;
use axenox\OAuth2Connector\Exceptions\OAuthInvalidStateException;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Security\AuthenticationProviderInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Exceptions\InvalidArgumentException;

trait OAuth2Trait
{    
    private $clientFacade = null;
    
    private $clientId = null;
    
    private $clientSecret = null;
    
    private $urlAuthorize = null;
    
    private $urlAccessToken = null;
    
    private $urlResourceOwnerDetails = null;
    
    private $provider = null;
    
    private $scopes = [];
    
    protected abstract function getAuthProvider() : AuthenticationProviderInterface;
    
    protected abstract function getTokenStored() : ?AccessTokenInterface;
    
    protected abstract function getRefreshToken(AccessTokenInterface $authenticatedToken) : ?string;
    
    protected function exchangeOAuthToken(AuthenticationTokenInterface $token) : OAuth2AuthenticatedToken
    {
        if ($token instanceof OAuth2AuthenticatedToken) {
            if ($token->getAccessToken()->hasExpired()) {
                throw new AuthenticationFailedError($this->getAuthProvider(), 'OAuth token expired: Please sign in again!');
            } else {
                return $token;
            }
        }
        
        if (! $token instanceof OAuth2RequestToken) {
            throw new RuntimeException('Cannot use "' . get_class($token) . '" as OAuth token!');
        }
        
        if ($token->getOAuthProviderHash() !== $this->getOAuthProviderHash()) {
            throw new AuthenticationFailedError($this->getAuthProvider(), 'OAuth token does not match the provider (hash mismatch)!');
        }
        
        $clientFacade = $this->getOAuthClientFacade();
        $request = $token->getRequest();
        $requestParams = $request->getQueryParams();
        $provider = $this->getOAuthProvider();
        
        switch (true) {
            
            // If we are not processing a provider response, either use the stored token
            // or redirect ot the provider to start authentication
            case empty($requestParams['code']):
                
                $authOptions = [];
                $oauthToken = $this->getTokenStored();
                if ($oauthToken) {
                    $expired = $oauthToken->hasExpired();
                    if ($expired) {
                        if (! $this->getRefreshToken($oauthToken)) {
                            $authOptions = ['prompt' => 'consent'];
                        } else {
                            $oauthToken = $provider->getAccessToken('refresh_token', [
                                'refresh_token' => $this->getRefreshToken($oauthToken)
                            ]);
                        }
                    }
                }
                if (! $oauthToken || ! empty($authOptions)) {
                    // If we don't have an authorization code then get one
                    $authUrl = $provider->getAuthorizationUrl($authOptions);
                    $redirectUrl = $request->getHeader('Referer')[0];
                    $clientFacade->startOAuthSession(
                        $this->getConnection(),
                        $this->getOAuthProviderHash(),
                        $redirectUrl,
                        [
                            'state' => $provider->getState()
                        ]);
                    $this->getWorkbench()->stop();
                    header('Location: ' . $authUrl);
                    exit;
                }
                break;
                
                // Got an error, probably user denied access
            case !empty($requestParams['error']):
                $clientFacade->stopOAuthSession();
                throw new AuthenticationFailedError($this, 'OAuth2 error: ' . htmlspecialchars($requestParams['error'], ENT_QUOTES, 'UTF-8'));
                
                // If code is not empty and there is no error, process provider response here
            default:
                $sessionVars = $clientFacade->getOAuthSessionVars();
                
                if (empty($requestParams['state']) || $requestParams['state'] !== $sessionVars['state']) {
                    $clientFacade->stopOAuthSession();
                    throw new OAuthInvalidStateException($this, 'Invalid OAuth2 state!');
                }
                
                // Get an access token (using the authorization code grant)
                try {
                    $oauthToken = $provider->getAccessToken('authorization_code', [
                        'code' => $requestParams['code']
                    ]);
                } catch (\Throwable $e) {
                    $clientFacade->stopOAuthSession();
                    throw new AuthenticationFailedError($this->getConnection(), $e->getMessage(), null, $e);
                }
        }
        
        $clientFacade->stopOAuthSession();
        if ($oauthToken) {
            return new OAuth2AuthenticatedToken($this->getUsername($oauthToken, $provider), $oauthToken, $token->getFacade());
        }
        
        throw new AuthenticationFailedError($this->getConnection(), 'Please sign in first!');
    }
    
    public function createLoginWidget(iContainOtherWidgets $container) : iContainOtherWidgets
    {
        $container
        ->addWidget($this->createButtonWidget($container))
        ->addWidget(WidgetFactory::createFromUxonInParent($container, new UxonObject([
            'attribute_alias' => 'AUTH_TOKEN_CLASS',
            'value' => '\\' . OAuth2RequestToken::class,
            'widget_type' => 'InputHidden'
        ])));
        return $container;
    }
    
    protected function getOAuthProvider() : AbstractProvider
    {
        $options = [
            'clientId'                  => $this->getClientId(),
            'clientSecret'              => $this->getClientSecret(),
            'redirectUri'               => $this->getRedirectUri(),
            'urlAuthorize'              => $this->getUrlAuthorize(),
            'urlAccessToken'            => $this->getUrlAccessToken(),
            'urlResourceOwnerDetails'   => $this->getUrlResourceOwnerDetails()
        ];
        return new GenericProvider($options);
    }
    
    /**
     * 
     * @return string
     */
    public function getClientId() : string
    {
        return $this->clientId;
    }
    
    /**
     * The client ID assigned to you by the provider
     * 
     * @uxon-property client_id
     * @uxon-type string
     * @uxon-required true
     * 
     * @param string $value
     * @return AuthenticationProviderInterface
     */
    public function setClientId(string $value) : AuthenticationProviderInterface
    {
        $this->clientId = $value;
        return $this;
    }
    
    /**
     * The client password assigned to you by the provider
     * 
     * @uxon-property client_secret
     * @uxon-type string
     * @uxon-required true
     * 
     * @return string
     */
    protected function getClientSecret() : string
    {
        return $this->clientSecret;
    }
    
    /**
     * 
     * @param string $value
     * @return AuthenticationProviderInterface
     */
    protected function setClientSecret(string $value) : AuthenticationProviderInterface
    {
        $this->clientSecret = $value;
        return $this;
    }
    
    /**
     * 
     * @param iContainOtherWidgets $container
     * @return WidgetInterface
     */
    protected function createButtonWidget(iContainOtherWidgets $container) : WidgetInterface
    {
        return WidgetFactory::createFromUxonInParent($container, new UxonObject([
            'widget_type' => 'Html',
            'hide_caption' => false,
            'inline' => true,
            'html' => <<<HTML
            
<a href="{$this->getOAuthClientFacade()->buildUrlForProvider($this, $this->getOAuthProviderHash())}" referrerpolicy="unsafe-url">
    <span style="float: left">
        <i style="padding: 3px 8px 3px 8px; font-size: 40px; color: gray" class="fa fa-key"></i>
    </span>
    <span style="line-height: 40px; display: inline-block; margin: 3px; padding: 0 8px 0 8px; font-weight: bold;">
        {$this->getWorkbench()->getApp('axenox.OAuth2Connector')->getTranslator()->translate('SIGN_IN_WITH')} OAuth 2.0
    </span>
</a>

HTML
        ]));
    }
    
    /**
     *
     * @return OAuth2ClientFacade
     */
    protected function getOAuthClientFacade() : OAuth2ClientFacade
    {
        if ($this->clientFacade === null) {
            $this->clientFacade = FacadeFactory::createFromString(OAuth2ClientFacade::class, $this->getWorkbench());;
        }
        return $this->clientFacade;
    }
    
    /**
     *
     * @return string
     */
    protected function getRedirectUri() : string
    {
        return $this->getOAuthClientFacade()->buildUrlToFacade(false);
    }
    
    protected function getUsername(AccessTokenInterface $oauthToken, AbstractProvider $oauthProvider) : ?string
    {
        $ownerDetails = $oauthProvider->getResourceOwner($oauthToken);
        return $ownerDetails->getEmail();
    }
    
    /**
     *
     * @return string
     */
    protected function getUrlAuthorize() : string
    {
        return $this->urlAuthorize;
    }
    
    /**
     * The URL to start the authorization process
     *
     * @uxon-property url_access_token
     * @uxon-type uri
     * 
     * @param string $value
     * @return AuthenticationProviderInterface
     */
    protected function setUrlAuthorize(string $value) : AuthenticationProviderInterface
    {
        $this->urlAuthorize = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    protected function getUrlAccessToken() : string
    {
        return $this->urlAccessToken;
    }
    
    /**
     * The URL to get the access token from
     *
     * @uxon-property url_access_token
     * @uxon-type uri
     *
     * @param string $value
     * @return AuthenticationProviderInterface
     */
    protected function setUrlAccessToken(string $value) : AuthenticationProviderInterface
    {
        $this->urlAccessToken = $value;
        return $this;
    }
    
    /**
     * @return string
     */
    protected function getUrlResourceOwnerDetails() : string
    {
        return $this->urlResourceOwnerDetails;
    }
    
    /**
     * The URL to get the authenticated user data (name, email, etc.)
     *
     * @uxon-property url_resource_owner_details
     * @uxon-type uri
     * 
     * @param string $value
     * @return AuthenticationProviderInterface
     */
    protected function setUrlResourceOwnerDetails(string $value) : AuthenticationProviderInterface
    {
        $this->urlResourceOwnerDetails = $value;
        return $this;
    }
    
    /**
     * 
     * @return string[]
     */
    protected function getScopes() : array
    {
        return $this->scopes;
    }
    
    /**
     * The scopes to require
     * 
     * @uxon-property scopes
     * @uxon-type array
     * @uxon-template [""]
     * 
     * @param string[] $arrayOrUxon
     * @return AuthenticationProviderInterface
     */
    protected function setScopes($arrayOrUxon) : AuthenticationProviderInterface
    {
        switch (true) { 
            case $arrayOrUxon instanceof UxonObject:        
                $this->scopes = $arrayOrUxon->toArray();
                break;
            case is_array($arrayOrUxon):
                $this->scopes = $arrayOrUxon;
                break;
            default:
                throw new InvalidArgumentException('Invalid authenticator configuration for OAuth scopes: expecting array, got ' . gettype($arrayOrUxon));
        }
        return $this;
    }
    
    protected function getOAuthProviderHash() : string
    {
        return md5($this->getClientId() . $this->getUrlAuthorize());
    }
}