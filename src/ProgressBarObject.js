/**
 * Import dependencies.
 */
import { Component } from '@wordpress/element';
import newId from './helper/getid';
import {showError} from "./setup";

/**
 * Check the state of the progress.
 */
function getProcessInfo( object ) {
  setTimeout(() => {
    object.props.object.setState( { 'button_disabled': true });

    fetch( wp_easy_setup.process_info_url, {
      method: 'POST',
      headers: {
        'Access-Control-Allow-Origin': '*',
        'Content-Type': 'application/json',
        'X-WP-Nonce': wp_easy_setup.rest_nonce
      },
      body: JSON.stringify({
        'config_name': object.props.object.props.config.name,
      })
    } )
    .then( response => response.json() )
    .then( function (result) {
      if( result.step > 0 && result.max > 0 ) {
        // set progress.
        document.getElementById( object.progressbar_id ).value = ((result.step / result.max) * 100);
      }

      // set label.
      document.getElementById( object.label_id ).innerHTML = result.step_label;

      // run info-check again if progress is running.
      if( 1 === result.running ) {
        getProcessInfo( object );
      }
      else {
        // enable finish button.
        object.props.object.setState( { 'finish_button_disabled': false });
      }
    })
    .catch( error => showError( error ) )
  }, 500)
}

/**
 * Declare our custom ProgressBar-object
 */
export default class ProgressBarObject extends Component {
  constructor() {
    super( ...arguments );
    this.progressbar_id = newId();
    this.label_id = newId();
  }

  /**
   * Start processing the setup.
   */
  componentDidMount() {
    // start process.
    setTimeout( ()  => {
      fetch( wp_easy_setup.process_url, {
          method: 'POST',
          headers: {
            'Access-Control-Allow-Origin': '*',
            'Content-Type': 'application/json',
            'X-WP-Nonce': wp_easy_setup.rest_nonce
          },
          body: JSON.stringify({
            'config_name': this.props.object.props.config.name,
          })
        } )
        .catch( error => showError( error ) )
      // get info about process every x ms.
      getProcessInfo( this );
    }, 500 );
  }

  /**
   * Render the output.
   *
   * @returns {JSX.Element}
   */
  render() {
    return <div
      className="wp-easy-setup-progressbar components-base-control__field"
    >
      <label>{this.props.field.label}</label>
      <progress id={this.progressbar_id} max="100" value="0">&nbsp;</progress>
      <p id={this.label_id}></p>
    </div>
  }
};
